/**
 * A component in the page: its state, the context its compiled code runs against ($this), and
 * rendering into its root element.
 *
 * State changes go through property setters: a write to $this->prop schedules an update (in a
 * microtask) of what reads the property; its view (runtime/view/engines.js) renders only that again.
 * Arrays are values (every write builds a new one), so a change is always a new top-level value.
 * A new snapshot from the server renders all of it.
 */
import * as P from '../../php.js';
import { merge as mergeValues } from './merge.js';
import { ErrorBag } from './errors.js';
import { RUNTIME_ATTRIBUTES } from '../view/attributes.js';
import { slotHasContent } from '../view/render.js';

/**
 * An enum property (public Status $status): the state holds its value, as the server sends it and
 * fusion:model writes it; compiled code reads the case (null for a value that names none).
 */
export function enumCase(runtime, prop, value) {
    return value === null || value === undefined ? null : runtime.classes[prop.enum].tryFrom(value);
}

/** What compiled code assigns to an enum property: a case (stored as its value) or null, as PHP's type allows. */
export function enumValue(prop, name, value) {
    if (value instanceof P.EnumCase) return value.value;
    if (value === null || value === undefined) return null;
    throw new P.PhpError('TypeError', `Cannot assign ${P.typeName(value)} to property $${name}: it takes a case of its enum`);
}

/** The state inside a signed envelope: {name, id, data: Map, errors}. */
export function readState(envelope) {
    const state = P.decode(JSON.parse(envelope.state));
    const get = (key) => (state instanceof Map ? state.get(key) : undefined);
    const data = get('data');
    const memo = get('memo');
    return {
        name: get('name'),
        id: get('id'),
        data: data instanceof Map ? data : new Map(),
        errors: new ErrorBag(memo instanceof Map ? memo.get('errors') ?? null : null),
        /** A page's properties loaded after it is shown ($this->defer()). */
        deferred: memo instanceof Map ? P.entries(memo.get('deferred') ?? []).map(([, name]) => name) : [],
        /** A partial reload's merge props: property => its rule (Merge). */
        merge: memo instanceof Map && memo.get('merge') instanceof Map ? memo.get('merge') : new Map(),
        /** A page's scroll props: property => where its paginator is ($this->scroll()). */
        scroll: memo instanceof Map && memo.get('scroll') instanceof Map ? memo.get('scroll') : new Map(),
        /** A component placed in a Blade view: its slots' HTML (no parent renders them in the browser). */
        slots: memo instanceof Map && memo.get('slots') instanceof Map ? memo.get('slots') : null,
    };
}

/** A merge rule as it came (a Map): {mode, path, match}. */
function readRule(rule) {
    return { mode: rule.get('mode'), path: rule.get('path') ?? null, match: rule.get('match') ?? [] };
}

/** Two PHP values are the same (as encoded: types count). */
function sameValue(a, b) {
    return a === b || JSON.stringify(P.encode(a ?? null)) === JSON.stringify(P.encode(b ?? null));
}

export class Component {
    /**
     * @param {object} runtime      the Fusion runtime (registry, network, bus, ...)
     * @param {object} definition   the compiled definition (Fusion.define)
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
        /** element => (attribute => {event, modifiers, fn, key}): its fusion:<event> handlers, several per event (fusion:keydown.enter and fusion:keydown.escape) */
        this.handlers = new WeakMap();
        /** The event types it has handlers for. */
        this.events = new Set();
        this.children = new Map();
        this.pending = new Set();
        /** #[Computed] values, kept until the state changes. */
        this.memo = new Map();
        /** Properties changed since the last update ("*": anything). */
        this.dirty = new Set();
        /** JavaScript from $this->js() (and functions from Fusion::browser()), to run after the next render. */
        this.pendingJs = [];
        /** What Fusion::browser() opened (sockets): closed when it leaves the page. */
        this.cleanups = [];
        /** The slots its parent passed: name => {nodes, instance, scope} (the parent renders them). */
        this.slots = {};
        /** The component whose view placed it ($parent), and fusion:model on its tag: {parent, path}. */
        this.parent = null;
        this.model = null;
        this.scheduled = false;
        this.destroyed = false;
        /** Renders of what changed so far (fusion:visible: whether a call changed anything). */
        this.renders = 0;
        this.ctx = this.context();
        /** The stores it uses: a change in one renders again what reads its property here. */
        for (const [property, store] of Object.entries(this.definition.stores ?? {})) {
            this.runtime.storeNamed(store).subscribe(this, property);
        }
        this.view = this.createView();
    }

    /** The $this of compiled code: properties, methods, and Fusion's actions. */
    context() {
        const self = this;
        const ctx = Object.create(null);

        for (const [name, prop] of Object.entries(this.definition.props)) {
            Object.defineProperty(ctx, name, {
                enumerable: true,
                get: () => (prop.enum ? enumCase(self.runtime, prop, self.data.get(name)) : self.data.get(name) ?? null),
                set: (value) => self.write(name, prop.enum ? enumValue(prop, name, value) : value, prop),
            });
        }

        for (const [name, fn] of Object.entries(this.definition.methods)) {
            ctx[name] = fn;
        }

        /** public Cart $cart: the tab's store, shared with every component that uses it. */
        for (const [property, store] of Object.entries(this.definition.stores ?? {})) {
            Object.defineProperty(ctx, property, { enumerable: true, get: () => self.runtime.storeNamed(store).ctx });
        }

        Object.assign(ctx, {
            $errors: undefined,
            $call: (method, args) => self.runtime.network.call(self, method, args),
            $computed: (name) => self.computed(name),
            $dispatch: (event, params) => self.dispatch(event, params),
            $parent: (method, args) => self.callParent(method, args),
            $reset: (names) => self.reset(names),
            $redirect: (url) => self.runtime.navigator.visit(P.toStr(url)),
            $set: (path, value) => self.setPath(path, value),
            $toggle: (path) => self.setPath(path, !P.bool(self.getPath(path))),
            $refresh: () => self.definition.server.length ? self.runtime.network.call(self, null, []) : self.render(),
            $js: (code) => self.js(code),
            $reload: (names) => self.runtime.reload(self, names),
            $loadMore: (name) => self.runtime.loadMore(self, P.toStr(name)),
            /** $this->hasMore('posts'): whether the scroll prop has a next page. */
            $hasMore: (name) => self.nextPage(P.toStr(name)) !== null,
            /** @deferred: whether any of the properties (any, when none are named) is still loading. */
            $deferring: (names) => (names.length ? names.some((name) => self.deferred.has(name)) : self.deferred.size > 0),
        });
        Object.defineProperty(ctx, '$errors', { get: () => self.errors });
        /** Fusion::browser(): what its APIs (js/functions/browser/_browser_*.js) need of the component. */
        Object.defineProperty(ctx, '$browser', { get: () => self.browser() });
        /** $slots->has('footer') and $slot->isEmpty() in its view. */
        ctx.$slots = { has: (name) => slotHasContent(self.slots[P.toStr(name)]) };

        return ctx;
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

    /** The element's first handler for the event (fusion:poll, fusion:init and fusion:visible have one). */
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

    /** #[Modelable]: what fusion:model on its tag binds goes back to the parent's property. */
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
        if (!parent || parent.destroyed) throw new Error(`Fusion [${this.name}]: $parent->${method}(): it has no parent component`);
        if (parent.definition.server.includes(method)) return parent.runtime.network.call(parent, method, args);
        if (typeof parent.definition.methods[method] !== 'function') throw new Error(`Fusion [${parent.name}]: ${method}() is not a method it has in the browser`);
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

    /** $set('form.email', 'x') and fusion:model: a property or a key under it. */
    setPath(path, value) {
        const [root, ...keys] = String(path).split('.');
        const prop = this.definition.props[root];
        if (prop?.enum && !keys.length) {
            /** fusion:model or $set on an enum property: its value as the page has it (the server checks it names a case); the hooks get the case. */
            this.write(root, value instanceof P.EnumCase ? value.value : value, prop);
            value = this.ctx[root];
        } else {
            this.ctx[root] = keys.length ? P.setIn(this.data.get(root) ?? null, keys, value) : value;
        }
        const hook = 'updated' + root.charAt(0).toUpperCase() + root.slice(1);
        if (typeof this.definition.methods[hook] === 'function') {
            this.ctx[hook](value, keys.length ? keys.join('.') : null);
        }
        /** updated($property, $value): any change, with its full path ("form.email"). */
        if (typeof this.definition.methods.updated === 'function') {
            this.ctx.updated(String(path), value);
        }
    }

    reset(names) {
        const props = names.length ? names : Object.keys(this.definition.props);
        for (const name of props) {
            const prop = this.definition.props[name];
            if (prop && 'default' in prop) this.ctx[name] = P.decode(prop.default);
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
     * then run the JavaScript $this->js() queued: it sees the page as the new state shows it.
     */
    update() {
        if (this.destroyed) return;
        const dirty = this.dirty;
        if (dirty.size > 0) {
            this.dirty = new Set();
            this.renders++;
            this.rendering(() => this.view.update(dirty) || this.rebuild());

            /** #[Url] properties that changed go to the query string. */
            if ([...dirty].some((name) => this.definition.props[name]?.url)) {
                this.runtime.navigator.syncUrl(this, dirty);
            }

            /** #[Remember] properties that changed go to the history entry. */
            if ([...dirty].some((name) => this.definition.props[name]?.remember)) {
                this.runtime.navigator.remember();
            }
        }
        this.runJs();
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
                else new Function('$fusion', '$refs', '$el', code).call(this.ctx, this.ctx, this.refs(), this.el);
            } catch (error) {
                this.runtime.report(this, error);
            }
        }
    }

    /**
     * The component, as Fusion::browser()'s APIs see it: its element and refs, work to do once it
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

    /** fusion:ref="name": the component's own elements by name (the first of a name). */
    refs() {
        const refs = {};
        for (const el of this.scan('[fusion\\:ref]')) {
            const name = el.getAttribute('fusion:ref');
            if (name && !(name in refs) && this.runtime.owner(el) === this) refs[name] = el;
        }
        return refs;
    }

    /** The browser hooks: mounted() once live in the page, destroyed() when it leaves (compiled, or #[Server]). */
    hook(name) {
        if (!this.definition.hooks?.includes(name)) return;
        if (this.definition.server.includes(name)) {
            this.runtime.network.call(this, name, []).catch(() => {});
            return;
        }
        try {
            this.ctx[name]();
        } catch (error) {
            this.runtime.report(this, error);
        }
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

    /** Run a render, then what follows it: the children it placed, handlers, models, hooks. False when it failed. */
    rendering(render) {
        this.placed = [];
        this.autofocus = null;
        try {
            render();
        } catch (error) {
            this.runtime.report(this, error);
            return false;
        }

        this.runtime.children(this, this.placed);
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
        const { data, errors, scroll } = readState(envelope);
        const fresh = new Map();
        this.setScroll(scroll, true);

        for (const [name, value] of data) {
            const current = this.data.get(name);
            if (this.data.has(name) && sent.get(name) !== current) {
                fresh.set(name, current);
            } else if (sameValue(current, value)) {
                fresh.set(name, current);
            } else {
                fresh.set(name, value);
                this.dirty.add(name);
            }
        }

        if (JSON.stringify([...errors.messages]) !== JSON.stringify([...this.errors.messages])) this.dirty.add('$errors');

        this.snapshot = envelope;
        this.serverData = new Map(data);
        this.data = fresh;
        this.errors = errors;
        this.memo.clear();
        const modelable = this.definition.modelable;
        const modelChanged = modelable && this.dirty.has(modelable);
        this.update();
        /** The server changed what fusion:model binds: the parent follows. */
        if (modelChanged) this.modelChanged(modelable);
        /** Its changes are the server's now, though nothing may have rendered again. */
        this.runtime.unsavedChanged(this);
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
        if (only.length > 0 && partial) {
            const { data, merge: rules, scroll } = readState(partial);
            this.setScroll(scroll, false);
            for (const [name, value] of data) {
                if (!only.includes(name)) continue;
                const current = this.data.get(name);
                const changedHere = this.data.has(name) && sent.get(name) !== current;
                /** A merge prop: merged into the value here, as the server merges it into its own (Merge::apply()). */
                const rule = rules.get(name);
                const into = (base) => (rule ? mergeValues(base ?? null, value, readRule(rule)) : value);
                this.serverData.set(name, into(this.serverData.get(name)));
                const next = into(current);
                if (!changedHere && !sameValue(current, next)) {
                    this.data.set(name, next);
                    this.dirty.add(name);
                }
            }
            this.partials.push(partial);
        } else {
            const { data, scroll } = readState(envelope);
            this.setScroll(scroll, true);
            const fresh = new Map(this.data);
            for (const [name, value] of data) {
                const current = this.data.get(name);
                const changedHere = this.data.has(name) && sent.get(name) !== current;
                if (!changedHere && !sameValue(current, value)) {
                    fresh.set(name, value);
                    this.dirty.add(name);
                }
            }
            this.snapshot = envelope;
            this.serverData = new Map(data);
            this.partials = [];
            this.data = fresh;
        }

        this.memo.clear();
        const modelable = this.definition.modelable;
        const modelChanged = modelable && this.dirty.has(modelable);
        this.update();
        if (modelChanged) this.modelChanged(modelable);
        this.runtime.unsavedChanged(this);
    }

    /**
     * Where its scroll props are, from a snapshot (replace: all of them) or a partial reload (the
     * ones it has). What reads $this->hasMore() renders again when it changes.
     */
    setScroll(scroll, replace) {
        const next = new Map(replace ? [] : this.scroll);
        for (const [name, position] of scroll) next.set(name, position);
        if (JSON.stringify(P.encode(next)) !== JSON.stringify(P.encode(this.scroll))) this.dirty.add('$scroll');
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
        const updates = {};
        for (const [name, value] of this.data) {
            if (this.definition.props[name]?.locked) continue;
            if (this.serverData.get(name) !== value) updates[name] = P.encode(value);
        }
        return updates;
    }

    /** It left the page: destroyed() runs (in the browser), then what it tracks is let go. */
    destroy() {
        if (this.destroyed) return;
        if (!this.definition.server.includes('destroyed')) this.hook('destroyed');
        this.destroyed = true;
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
