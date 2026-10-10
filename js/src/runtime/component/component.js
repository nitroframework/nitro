/**
 * A component in the page: its state, and rendering into its root element. The $this its compiled
 * code runs against is context.js's; the state the server signed, snapshot.js's.
 *
 * State changes go through property setters: a write to $this->prop schedules an update (in a
 * microtask) of what reads the property; its view (runtime/view/engines.js) renders only that again.
 * Arrays are values (every write builds a new one), so a change is always a new top-level value.
 * A new snapshot from the server renders all of it.
 */
import * as P from '../../php.js';
import { RUNTIME_ATTRIBUTES } from '../view/attributes.js';
import { componentHook, propertyHooks } from './hooks.js';
import { ATTRIBUTES, SIGNALS } from '../protocol.js';
import { defaultsOf, readState, reloadInto, sameValue, snapshotInto, updatesOf } from './snapshot.js';
import { contextOf, enumCase } from './context.js';
import { ErrorBag } from './errors.js';
import { Watchers } from './watchers.js';
import { handleOf } from './handle.js';

export class Component {
    /**
     * @param {object} runtime      the Nitro runtime (registry, network, bus, ...)
     * @param {object} definition   the compiled definition (Nitro.define)
     * @param {Element} el          the root element
     * @param {Map} data            the state (property => PHP value)
     * @param {object|null} snapshot the signed envelope from the server (null for browser-made children)
     */
    constructor(runtime, definition, el, id, name, data, snapshot, errors) {
        this.runtime = runtime;
        this.definition = definition;
        this.el = el;
        this.id = id;
        this.name = name;
        this.data = new Map(data);
        this.snapshot = snapshot;
        this.serverData = new Map(data);
        /** Partial reloads since the snapshot: signed envelopes of some properties, sent with the next #[Server] call. */
        this.partials = [];
        this.errors = errors ?? new ErrorBag();
        /** Its deferred properties not loaded yet (a page's: Network::deferred()). */
        this.deferred = new Set();
        /** Where its scroll props' paginators are: property => Map {pageName, currentPage, nextPage, previousPage}. */
        this.scroll = new Map();
        /** element => (attribute => {event, modifiers, fn, key}): its nitro:<event> handlers, several per event (nitro:keydown.enter and nitro:keydown.escape) */
        this.handlers = new WeakMap();
        /** The event types it has handlers for. */
        this.events = new Set();
        this.children = new Map();
        this.pending = new Set();
        /** #[Computed] values, kept until the state changes. */
        this.memo = new Map();
        /** Properties changed since the last update ("*": anything). */
        this.dirty = new Set();
        /** JavaScript from $this->js() (and functions from Nitro::browser()), to run after the next render. */
        this.pendingJs = [];
        /** What Nitro::browser() opened (sockets): closed when it leaves the page. */
        this.cleanups = [];
        /** The slots its parent passed: name => {nodes, instance, scope} (the parent renders them). */
        this.slots = {};
        /** The component whose view placed it ($parent), and nitro:model on its tag: {parent, path}. */
        this.parent = null;
        this.model = null;
        this.scheduled = false;
        this.destroyed = false;
        /** It failed and shows its fallback (fail()): it renders no more. */
        this.failed = false;
        /** Renders of what changed so far (nitro:visible: whether a call changed anything). */
        this.renders = 0;
        this.ctx = contextOf(this);
        /** handle.watch(): code outside the view told when a value changes (watchers.js). */
        this.watchers = new Watchers(this);
        /** The stores it uses: a change in one renders again what reads its property here. */
        for (const [property, store] of Object.entries(this.definition.stores ?? {})) {
            this.runtime.stores.named(store).subscribe(this, property);
        }
        this.view = this.createView();
    }

    /** What a render reports back: handlers, children, nodes it puts in the page and takes out. */
    createView() {
        const host = {
            on: (el, event, modifiers, fn, key = event) => {
                if (!this.handlers.has(el)) this.handlers.set(el, new Map());
                this.handlers.get(el).set(key, { event, modifiers, fn, key });
                this.events.add(event);
            },
            child: (name, key, props, slots, model) => {
                const id = `${this.id}.${key}`;
                this.placed.push({ name, id, props, slots, model });
                return id;
            },
            /** The content its parent passed for a slot (its nodes, the parent's view and scope). */
            slot: (name) => this.slots[name] ?? null,
            added: (node) => {
                this.autofocus ??= node.nodeType === 1 ? (node.matches('[autofocus]') ? node : node.querySelector('[autofocus]')) : null;
            },
            removed: (node) => this.runtime.forget(node),
            id: this.id,
        };
        return this.runtime.engines.view(this.definition, this.ctx, host, this.el.ownerDocument);
    }

    /** The parent rendered this child's tag again: its slots now come from there. */
    setSlots(slots) {
        this.slots = slots ?? {};
        this.view.refreshSlots();
    }

    /** The handlers a render gave the element for the event, in the order of its attributes. */
    handlersOf(el, event) {
        return [...(this.handlers.get(el)?.values() ?? [])].filter((handler) => handler.event === event);
    }

    /** The element's first handler for the event (nitro:poll, nitro:init and nitro:visible have one). */
    handler(el, event) {
        return this.handlersOf(el, event)[0] ?? null;
    }

    write(name, value, prop) {
        if (prop.locked) {
            throw new P.PhpError('Error', `$${name} is #[Locked]: it cannot change in the browser.`);
        }
        if (this.data.get(name) === value) return;
        this.data.set(name, value);
        this.changed(name);
        this.modelChanged(name);
    }

    /** #[Modelable]: what nitro:model on its tag binds goes back to the parent's property. */
    modelChanged(name) {
        if (name === this.definition.modelable && this.model) {
            this.model.parent.setPath(this.model.path, this.data.get(name) ?? null);
        }
    }

    /**
     * $this->dispatch('saved', ...): delivered once the method running is done, so ->to() or
     * ->self() (Livewire's) can still say to whom.
     */
    dispatch(event, params) {
        const target = { to: null, self: false };
        queueMicrotask(() => this.runtime.dispatch(event, params, this, target));
        const pending = {
            to: (name) => {
                target.to = P.toStr(name);
                return pending;
            },
            self: () => {
                target.self = true;
                return pending;
            },
        };
        return pending;
    }

    /** $parent->method(...) in a handler: the component around this one (compiled, or #[Server]). */
    callParent(method, args) {
        const parent = this.parent;
        if (!parent || parent.destroyed) throw new Error(`Nitro [${this.name}]: $parent->${method}(): it has no parent component`);
        if (parent.definition.server.includes(method)) return parent.runtime.network.call(parent, method, args);
        if (typeof parent.definition.methods[method] !== 'function') throw new Error(`Nitro [${parent.name}]: ${method}() is not a method it has in the browser`);
        return parent.ctx[method](...args);
    }

    /** A property changed ("*": any may have): #[Computed] values are stale, and what reads it renders again. */
    changed(name = '*') {
        this.memo.clear();
        this.dirty.add(name);
        this.schedule();
    }

    /** $this->total for a #[Computed] total(): worked out once until the state changes. */
    computed(name) {
        if (!this.memo.has(name)) this.memo.set(name, this.ctx[name]());
        return this.memo.get(name);
    }

    getPath(path) {
        const [root, ...keys] = String(path).split('.');
        return P.getIn(this.data.get(root) ?? null, keys);
    }

    /** $set('form.email', 'x') and nitro:model: a property or a key under it, between its updating and updated hooks. */
    setPath(path, value) {
        const [root, ...keys] = String(path).split('.');
        const prop = this.definition.props[root];
        const isEnum = prop?.enum && !keys.length;
        /** An enum property: its value as the page has it (the server checks it names a case); the hooks get the case. */
        const raw = isEnum && value instanceof P.EnumCase ? value.value : value;
        propertyHooks(this, 'updating', root, keys, isEnum ? enumCase(this.runtime, prop, raw) : value);
        if (isEnum) {
            this.write(root, raw, prop);
            value = this.ctx[root];
        } else {
            this.ctx[root] = keys.length ? P.setIn(this.data.get(root) ?? null, keys, value) : value;
        }
        propertyHooks(this, 'updated', root, keys, value);
    }

    reset(names) {
        const initial = defaultsOf(this.definition);
        for (const name of names.length ? names : Object.keys(this.definition.props)) {
            if (initial.has(name)) this.ctx[name] = initial.get(name);
        }
    }

    schedule() {
        if (this.scheduled || this.destroyed) return;
        this.scheduled = true;
        queueMicrotask(() => {
            this.scheduled = false;
            if (!this.destroyed) this.update();
        });
    }

    /**
     * Render again what reads the properties that changed (all of it when the root reads one),
     * then rendered(), then the JavaScript $this->js() queued: both see the page as the new state
     * shows it. What rendered() changes renders too, without calling it again for that render.
     */
    update() {
        if (this.destroyed) return;
        const dirty = this.dirty;
        if (dirty.size > 0) {
            this.dirty = new Set();
            this.renders++;
            const rendered = this.rendering(() => this.view.update(dirty) || this.rebuild());
            if (rendered && !this.inRendered) {
                this.inRendered = true;
                this.hook('rendered');
                if (this.dirty.size === 0) this.inRendered = false;
            } else {
                this.inRendered = false;
            }

            /** #[Url] properties that changed go to the query string. */
            if ([...dirty].some((name) => this.definition.props[name]?.url)) {
                this.runtime.navigator.syncUrl(this, dirty);
            }

            /** #[Remember] properties that changed go to the history entry. */
            if ([...dirty].some((name) => this.definition.props[name]?.remember)) {
                this.runtime.navigator.remember();
            }

            this.watchers.changed(dirty);
        }
        this.runJs();
    }

    /**
     * @nitroScript: the view's own JavaScript, once for this instance as it goes live, with $nitro
     * (as $this->js() has it), $refs, $el and $component (its handle, by the PHP's names). A
     * function it returns runs when the component leaves the page.
     */
    runScripts() {
        for (const code of this.definition.scripts ?? []) {
            try {
                const cleanup = new Function('$nitro', '$refs', '$el', '$component', code).call(this.ctx, this.ctx, this.refs(), this.el, this.handle());
                if (typeof cleanup === 'function') this.cleanups.push(cleanup);
            } catch (error) {
                this.runtime.report(this, error);
            }
        }
    }

    /** The component as JavaScript outside its view reaches it, by the PHP's names (handle.js): a handle of its own for each caller. */
    handle() {
        return handleOf(this.runtime, this.definition, this.ctx, this.id);
    }

    /** $this->js(): run once the component has rendered what changed. */
    js(code) {
        this.pendingJs.push(P.toStr(code));
        this.schedule();
    }

    runJs() {
        const pending = this.pendingJs;
        this.pendingJs = [];
        for (const code of pending) {
            try {
                if (typeof code === 'function') code();
                else new Function('$nitro', '$refs', '$el', code).call(this.ctx, this.ctx, this.refs(), this.el);
            } catch (error) {
                this.runtime.report(this, error);
            }
        }
    }

    /**
     * The component, as Nitro::browser()'s APIs see it: its element and refs, work to do once it
     * has rendered, what to close when it leaves, and a place to keep what they opened.
     */
    browser() {
        return (this.browserHandle ??= {
            el: this.el,
            ref: (name) => this.refs()[name] ?? null,
            after: (fn) => {
                this.pendingJs.push(fn);
                this.schedule();
            },
            onDestroy: (fn) => this.cleanups.push(fn),
            cache: new Map(),
            report: (error) => this.runtime.report(this, error),
        });
    }

    /** The elements matching the selector in its element and its @teleport content (its children's too). */
    scan(selector) {
        const all = [];
        for (const root of [this.el, ...this.view.teleported()]) {
            if (root.matches(selector)) all.push(root);
            all.push(...root.querySelectorAll(selector));
        }
        return all;
    }

    /** nitro:ref="name": the component's own elements by name (the first of a name). */
    refs() {
        const refs = {};
        for (const el of this.scan('[nitro\\:ref]')) {
            const name = el.getAttribute('nitro:ref');
            if (name && !(name in refs) && this.runtime.owner(el) === this) refs[name] = el;
        }
        return refs;
    }

    /** A component hook (hooks.js): mounted(), rendered(), destroyed(). */
    hook(name) {
        componentHook(this, name);
    }

    /** Render all of it again (what reads anything). */
    render() {
        this.changed('*');
    }

    /** Take over the server's HTML: the root element is there, rendered by Blade. */
    hydrate() {
        this.rendering(() => this.view.hydrate(this.el));
    }

    /**
     * A new root element, for a component made in the browser. The runtime puts it in the page
     * (place); then the children it placed are made.
     *
     * @param {(root: Element) => void} place
     */
    create(place) {
        return this.rendering(() => {
            const root = this.view.create();
            place(root);
            this.el = root;
        });
    }

    /** The root element made again (a block around it changed which it is). */
    rebuild() {
        const old = this.el;
        const root = this.view.create();
        for (const name of RUNTIME_ATTRIBUTES) {
            if (old.hasAttribute(name)) root.setAttribute(name, old.getAttribute(name));
        }
        for (const node of [...old.childNodes]) this.runtime.forget(node);
        old.replaceWith(root);
        this.el = root;
    }

    /**
     * It failed (a render here, a #[Server] call): the fallback's HTML in place of its view, marked
     * for the runtime to leave alone. It keeps its state; it renders no more until the page loads again.
     */
    fail(html) {
        this.failed = true;
        if (!this.el) return;
        for (const node of [...this.el.childNodes]) this.runtime.forget(node);
        const fallback = this.el.ownerDocument.createElement('div');
        fallback.setAttribute(ATTRIBUTES.fallback, '');
        fallback.innerHTML = html;
        this.el.replaceChildren(fallback);
    }

    /** Run a render, then what follows it: the children it placed, handlers, models, hooks. False when it failed. */
    rendering(render) {
        if (this.failed) return false;
        this.placed = [];
        this.autofocus = null;
        try {
            render();
        } catch (error) {
            this.runtime.report(this, error);
            /** With fallbacks on (@nitroScripts gives the default), it shows in the component's place. */
            if (typeof this.runtime.config.fallback === 'string') this.fail(this.runtime.config.fallback);
            return false;
        }

        this.runtime.placement.children(this, this.placed);
        this.runtime.afterRender(this);

        /** autofocus acts on page load only; a field that appears later (a modal's) gets it here. */
        this.autofocus?.focus();
        return true;
    }

    /**
     * The state after a #[Server] call; values changed here while it ran stay changed. What the
     * server changed renders again (a value equal to the one here stays the one here).
     */
    applySnapshot(envelope, sent) {
        snapshotInto(this, envelope, sent);
    }

    /**
     * A signed state from the server for a component made here (Network.adopt()): what the
     * server has becomes its last known state, and what is here stays, to be sent as changes.
     */
    adopt(envelope) {
        const state = readState(envelope);
        if (state.id !== this.id || state.name !== this.name) return;
        this.snapshot = envelope;
        this.serverData = new Map(state.data);
        this.runtime.unsavedChanged(this);
    }

    /**
     * The state after a reload ($reload, deferred properties). Of some properties: their signed
     * values (partial) are taken, unless changed here while it ran, and the envelope is kept for
     * the next #[Server] call, which lays it over the snapshot. Of all of them: the new snapshot
     * is the state, again keeping what changed here meanwhile.
     */
    applyReload(envelope, only, sent, partial = null) {
        reloadInto(this, envelope, only, sent, partial);
    }

    /**
     * Where its scroll props are, from a snapshot (replace: all of them) or a partial reload (the
     * ones it has). What reads $this->hasMore() renders again when it changes.
     */
    setScroll(scroll, replace) {
        const next = new Map(replace ? [] : this.scroll);
        for (const [name, position] of scroll) next.set(name, position);
        if (JSON.stringify(P.encode(next)) !== JSON.stringify(P.encode(this.scroll))) this.dirty.add(SIGNALS.scroll);
        this.scroll = next;
    }

    /** A scroll prop's next page (a number or a cursor), or null when it has none. */
    nextPage(name) {
        const position = this.scroll.get(name);
        return position instanceof Map ? position.get('nextPage') ?? null : null;
    }

    /**
     * The properties (or paths: "form.email") whose value here is not the one the server last
     * signed: of those given, or of all. Locked ones never change here.
     *
     * @param {string[]|null} paths
     */
    unsaved(paths = null) {
        const read = (map, path) => path.split('.').slice(1).reduce((v, k) => P.get(v, k), map.get(path.split('.')[0]) ?? null);
        const names = paths ?? [...this.data.keys()].filter((name) => !this.definition.props[name]?.locked);
        return names.filter((path) => !sameValue(read(this.data, path), read(this.serverData, path)));
    }

    /** Values changed in the browser since the last snapshot (sent with the next #[Server] call). */
    updates() {
        return updatesOf(this);
    }

    /** It left the page: destroyed() runs (a #[Server] one is sent before it goes), then what it tracks is let go. */
    destroy() {
        if (this.destroyed) return;
        this.hook('destroyed');
        this.destroyed = true;
        this.watchers.clear();
        this.runtime.broadcasts.unsubscribe(this);
        for (const store of Object.values(this.definition.stores ?? {})) {
            this.runtime.stores.get(store)?.unsubscribe(this);
        }
        for (const cleanup of this.cleanups.splice(0)) {
            try {
                cleanup();
            } catch (error) {
                this.runtime.report(this, error);
            }
        }
        this.view.dispose();
    }
}
