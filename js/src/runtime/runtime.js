/**
 * Fusion in the page: the compiled definitions (Fusion.define, from app.js and the #[LazyLoad]
 * chunks it loads when a page needs them), the components currently on the page, and the
 * services they share (events, models, network, navigation).
 */
import { globals } from '../laravel.js';
import * as P from '../php.js';
import { Component, readState } from './component.js';
import { ErrorBag } from './errors.js';
import { Events } from './events.js';
import { Hooks } from './hooks.js';
import { islandsIn, whenToStart } from './islands.js';
import { Broadcasts } from './echo.js';
import { applyLoading, STYLE } from './loading.js';
import { Model } from './model.js';
import { Navigator } from './navigate.js';
import { Network } from './network.js';

export class Runtime {
    constructor(window, config) {
        this.window = window;
        this.document = window.document;
        this.config = config;
        this.definitions = new Map();
        /** #[LazyLoad]: component => chunk; [path pattern, chunk] of the pages; chunk => its load */
        this.lazyComponents = new Map();
        this.lazyRoutes = [];
        this.chunks = new Map();
        this.instances = new Map();
        this.inflight = new WeakMap();
        /** Signed state of the children with mount() the server created for the page being rendered, by id. */
        this.provided = new Map();
        /** Fusion::once() shared data the browser keeps between responses: key => {value, expires (ms or null)}. */
        this.onceShared = new Map();
        this.events = new Events(this, this.document);
        this.hooks = new Hooks(this, window);
        /** #[On('echo:...')]: broadcast events, through the application's Laravel Echo. */
        this.broadcasts = new Broadcasts(this, window);
        this.model = new Model(this, this.document);
        this.network = new Network(this, config);
        this.navigator = new Navigator(this, window);
        this.bus = { dispatch: (event, params, source) => this.dispatch(event, params, source) };
    }

    /** Called by app.js (and the chunks) for every compiled component. */
    define(name, definition) {
        this.definitions.set(name, definition);
    }

    /**
     * Called by app.js with the #[LazyLoad] components (name => chunk) and the pages that render
     * them ([path pattern, chunk]).
     */
    lazy(components, routes) {
        this.lazyComponents = new Map(Object.entries(components));
        this.lazyRoutes = routes.map(([pattern, group]) => [new RegExp(pattern), group]);
    }

    /** A chunk's components: loaded once, from where @fusionScripts says chunks are (data-chunks). */
    load(group) {
        /** Its components are here already (the page loaded the chunk with its own script tag). */
        const inGroup = [...this.lazyComponents].filter(([, chunk]) => chunk === group).map(([name]) => name);
        if (!this.chunks.has(group) && inGroup.length > 0 && inGroup.every((name) => this.definitions.has(name))) {
            this.chunks.set(group, Promise.resolve());
        }
        if (!this.chunks.has(group)) {
            this.chunks.set(group, new Promise((resolve, reject) => {
                const script = this.document.createElement('script');
                const pattern = this.config.chunks ?? `${this.config.endpoint}/app/{id}.js?v=${encodeURIComponent(this.config.bundle ?? '')}`;
                script.src = pattern.replace('{id}', encodeURIComponent(group));
                script.onload = () => resolve();
                script.onerror = () => {
                    this.chunks.delete(group);
                    reject(new Error(`Fusion: the components of chunk [${group}] did not load`));
                };
                this.document.head.appendChild(script);
            }));
        }
        return this.chunks.get(group);
    }

    /** The chunks the components need that are not here yet: a promise, or null when none are missing. */
    require(names) {
        const groups = new Set();
        for (const name of names) {
            if (!this.definitions.has(name) && this.lazyComponents.has(name)) groups.add(this.lazyComponents.get(name));
        }
        return groups.size ? Promise.all([...groups].map((group) => this.load(group))) : null;
    }

    /** A visit to the path: start loading the chunk of the page it renders, while its data loads. */
    preload(path) {
        for (const [pattern, group] of this.lazyRoutes) {
            if (pattern.test(path)) this.load(group).catch(() => {});
        }
    }

    /**
     * Take over the server-rendered components under root (once the chunks they need are here).
     * Islands with fusion:hydrate, and the components inside them, wait for their moment (not
     * once their page is gone: a fusion:navigate visit replaced it). Resolves when the page's
     * components are live.
     */
    start(root = this.document.body) {
        if (!this.styled) {
            const style = this.document.createElement('style');
            style.textContent = STYLE;
            this.document.head.appendChild(style);
            this.styled = true;
        }

        const islands = islandsIn(root);
        const elements = snapshotsIn(root).filter((el) => !islands.some((island) => island.contains(el)));

        const live = this.take(elements).then(() => {
            this.navigator.current();
            /** A reload: what #[Remember] properties had in this history entry. */
            this.restoreRemembered(this.window.history.state?.fusionRemember);
            /** The page's once data, kept for the responses after it; its flash data, announced. */
            this.withOnce(globals.shared, globals.once ?? {});
            this.announceFlash(globals.flash);
        });

        for (const island of islands) {
            whenToStart(island, this.window, () => {
                if (!island.isConnected) return Promise.resolve();
                island.removeAttribute('fusion:hydrate');
                /** Not those of an island inside it that is still waiting. */
                const waiting = islandsIn(island);
                return this.take(snapshotsIn(island).filter((el) => !waiting.some((inner) => inner.contains(el))));
            });
        }
        return live;
    }

    /**
     * Make the elements' components live, once the chunks they need are here: @fusionScripts
     * loads those of the page it renders; this is for any it could not know of (and islands').
     */
    take(elements) {
        const go = () => {
            const created = elements.map((el) => this.hydrate(el)).filter(Boolean);
            created.forEach((component) => component.hydrate());
            /** mounted() once they are all live (a child's is there for its parent's). */
            created.forEach((component) => component.hook('mounted'));
            created.forEach((component) => this.broadcasts.subscribe(component));
            created.forEach((component) => this.loadDeferred(component));
        };

        const waiting = this.require(elements.map((el) => nameIn(el.getAttribute('fusion:snapshot'))));
        if (!waiting) {
            go();
            return Promise.resolve();
        }
        return waiting.then(go, (error) => {
            this.report(null, error);
            go();
        });
    }

    /** A page from fusion:navigate: the names of its components with state from the server. */
    pageComponents(page) {
        return [page.snapshot, ...Object.values(page.children ?? {})].map((envelope) => nameIn(envelope));
    }

    hydrate(el) {
        let envelope;
        let state;
        try {
            envelope = JSON.parse(el.getAttribute('fusion:snapshot'));
            state = readState(envelope);
        } catch (error) {
            this.report(null, new Error('Fusion: unreadable component state'));
            return null;
        }
        el.removeAttribute('fusion:snapshot');

        const definition = this.definitions.get(state.name);
        if (!definition) {
            this.report(null, new Error(`Fusion: component [${state.name}] is not compiled (is @fusionScripts on the page?)`));
            return null;
        }

        const component = new Component(this, definition, el, state.id, state.name, state.data, envelope, state.errors);
        component.deferred = new Set(state.deferred);
        if (state.slots) component.slots = Object.fromEntries([...state.slots].map(([name, html]) => [name, { html: P.toStr(html) }]));
        this.instances.set(state.id, component);
        return component;
    }

    find(id) {
        return this.instances.get(id) ?? null;
    }

    /**
     * The component an element belongs to: the one whose view rendered it. That is the closest
     * component element around it, unless a view marked it as its own (fusionOwner): a parent's
     * slot content inside a child, content teleported elsewhere.
     */
    owner(el) {
        for (let node = el; node; node = node.parentElement) {
            if (node.fusionOwner !== undefined) return this.find(node.fusionOwner);
            if (node.hasAttribute?.('fusion:id')) return this.find(node.getAttribute('fusion:id'));
        }
        return null;
    }

    components() {
        return [...this.instances.values()];
    }

    /** Components whose element left the page (or is about to). */
    forget(node) {
        for (const [id, component] of this.instances) {
            if (component.el === node || node.contains?.(component.el)) {
                component.destroy();
                this.instances.delete(id);
            }
        }
    }

    /**
     * After a render: the children it placed. Existing ones keep their state (a child without
     * mount() takes the new props); new ones are created in the browser, which only a component
     * without mount() can be (mount() runs on the server).
     */
    children(parent, specs) {
        for (const spec of specs) {
            const existing = this.instances.get(spec.id);
            const definition = this.definitions.get(spec.name);

            if (existing) {
                existing.parent = parent;
                /** The parent rendered its tag again: the slots come from there now. */
                if (spec.slots) existing.setSlots(spec.slots);
                /** fusion:model on its tag: its #[Modelable] property follows the parent's value. */
                if (spec.model && definition?.modelable && '$model' in spec.props) {
                    existing.model = { parent, path: spec.model };
                    if (existing.data.get(definition.modelable) !== spec.props.$model) {
                        existing.data.set(definition.modelable, spec.props.$model);
                        existing.changed(definition.modelable);
                    }
                }
                if (definition && !definition.mount) {
                    for (const [prop, value] of Object.entries(spec.props)) {
                        if (existing.data.get(prop) !== value && prop in definition.props) {
                            existing.data.set(prop, value);
                            existing.serverData.set(prop, value);
                            existing.changed(prop);
                        }
                    }
                }
                continue;
            }

            const placeholder = parent.scan('[fusion\\:placeholder]').find((el) => el.getAttribute('fusion:id') === spec.id);
            if (!placeholder) continue;

            /** A #[LazyLoad] child: made once its chunk is here (its placeholder waits for it). */
            const waiting = definition ? null : this.require([spec.name]);
            if (waiting) {
                waiting.then(() => this.children(parent, [spec]), (error) => this.report(parent, error));
                continue;
            }

            if (!definition) {
                this.report(parent, new Error(`Fusion: component [${spec.name}] is not compiled`));
                continue;
            }

            if (definition.mount) {
                /** Created by the server for the page being rendered (mountPage()). */
                const envelope = this.provided.get(spec.id);
                const state = envelope ? readState(envelope) : null;
                if (!state || state.id !== spec.id || state.name !== spec.name) {
                    this.report(parent, new Error(`Fusion: [${spec.name}] has mount(), which runs on the server: it cannot be created in the browser. Render it on the server, or pass its data as properties instead of mount() parameters.`));
                    continue;
                }
                this.provided.delete(spec.id);
                this.create(placeholder, definition, state, envelope, {}, spec.slots, { parent, model: spec.model ? { parent, path: spec.model } : null });
                continue;
            }

            const data = new Map();
            for (const [name, prop] of Object.entries(definition.props)) {
                if ('default' in prop) data.set(name, P.decode(prop.default));
            }
            for (const [name, value] of Object.entries(spec.props)) {
                if (name === '$model') {
                    if (!definition.modelable) {
                        this.report(parent, new Error(`Fusion: fusion:model on <fusion:${spec.name}>: it has no #[Modelable] property`));
                        continue;
                    }
                    data.set(definition.modelable, value);
                    continue;
                }
                if (!(name in definition.props)) {
                    this.report(parent, new Error(`Fusion: [${spec.name}] has no property $${name}`));
                    continue;
                }
                data.set(name, value);
            }

            this.create(placeholder, definition, { id: spec.id, name: spec.name, data, errors: new ErrorBag() }, null, {}, spec.slots, { parent, model: spec.model ? { parent, path: spec.model } : null });
        }
    }

    /**
     * A component the browser renders itself, in place of the element given: a child, or a page
     * (with its server-made state). Its render places its children, made after it is in the page.
     */
    create(placeholder, definition, state, envelope, attributes = {}, slots = {}, links = {}) {
        const component = new Component(this, definition, placeholder, state.id, state.name, state.data, envelope, state.errors);
        component.slots = slots ?? {};
        component.parent = links.parent ?? null;
        component.model = links.model ?? null;
        component.deferred = new Set(state.deferred ?? []);
        const made = component.create((root) => {
            root.setAttribute('fusion:id', state.id);
            root.setAttribute('fusion:name', state.name);
            for (const [name, value] of Object.entries(attributes)) root.setAttribute(name, value);
            placeholder.replaceWith(root);
            this.instances.set(state.id, component);
        });
        if (!made) return null;
        component.hook('mounted');
        this.broadcasts.subscribe(component);
        this.loadDeferred(component);
        return component;
    }

    /**
     * #[Remember]: the remembered properties of the components on the page, by component (its
     * name and its place among those of that name); null when there are none.
     */
    remembered() {
        const saved = {};
        for (const [key, component] of this.rememberKeys()) {
            const values = {};
            for (const [name, prop] of Object.entries(component.definition.props)) {
                if (prop.remember) values[name] = P.encode(component.data.get(name) ?? null);
            }
            if (Object.keys(values).length > 0) saved[key] = values;
        }
        return Object.keys(saved).length > 0 ? saved : null;
    }

    /** #[Remember]: the values the history entry kept, back in the components they belong to. */
    restoreRemembered(saved) {
        if (!saved || typeof saved !== 'object') return;
        for (const [key, component] of this.rememberKeys()) {
            for (const [name, value] of Object.entries(saved[key] ?? {})) {
                const prop = component.definition.props[name];
                if (prop?.remember) component.write(name, P.decode(value), prop);
            }
        }
    }

    /** @returns {Array<[string, Component]>} each component on the page with its key: "name#n" */
    rememberKeys() {
        const seen = new Map();
        return this.components().filter((component) => !component.destroyed).map((component) => {
            const n = seen.get(component.name) ?? 0;
            seen.set(component.name, n + 1);
            return [`${component.name}#${n}`, component];
        });
    }

    /** A page with deferred properties asks for them once it is shown. */
    loadDeferred(component) {
        if (component.deferred.size > 0) this.network.reload(component, [...component.deferred], true).catch(() => {});
    }

    /** $reload / $this->reload(): a page's properties, from its route (all of them when none are named). */
    reload(component, only = []) {
        if (!component.definition.page) {
            const error = new Error(`Fusion: [${component.name}] is a component; only pages reload their properties`);
            this.report(component, error);
            return Promise.reject(error);
        }
        return this.network.reload(component, only.map(P.toStr));
    }

    /**
     * fusion:navigate: render the page the server described (Fusion::pageData()) in place of the
     * current one. False when it cannot be done here (the page loads normally then).
     */
    mountPage(current, page) {
        let state;
        try {
            state = readState(page.snapshot);
        } catch {
            return false;
        }
        const definition = this.definitions.get(state.name);
        if (!definition) return false;

        this.provided = new Map(Object.entries(page.children ?? {}));
        this.forget(current);
        try {
            return this.create(current, definition, state, page.snapshot, { 'fusion:page': page.layout }) !== null;
        } finally {
            this.provided.clear();
        }
    }

    afterRender(component) {
        this.events.watch(component);
        this.model.sync(component);
        applyLoading(component, this.inflight.get(component) ?? new Set());
        this.hooks.sync(component);
    }

    loading(component, method, on) {
        const methods = this.inflight.get(component) ?? new Set();
        const name = method ?? '$refresh';
        on ? methods.add(name) : methods.delete(name);
        this.inflight.set(component, methods);
        applyLoading(component, methods);
    }

    /**
     * Fusion::share()'s data from the server (a #[Server] call's answer, a visited page). When it
     * changed, what reads it ("$shared") renders again; `except` renders with its own update.
     */
    /**
     * What a response brings besides state: Fusion::share()'s data (with the once data kept
     * from earlier responses) and Fusion::flash()'s. Flash data belongs to the page it came with:
     * a visit replaces it; a reload or a #[Server] call only when it brings some.
     */
    receive(answer, except = null, visit = false) {
        if (answer.shared !== undefined) this.setShared(this.withOnce(answer.shared, answer.once ?? {}), except);
        if (answer.flash === undefined || answer.flash === null) return;
        const flash = P.decode(answer.flash);
        if (visit || (flash instanceof Map && flash.size > 0)) this.setFlash(answer.flash, except);
    }

    /**
     * Fusion::once(): the once data a response sent is kept; what it left out (the browser said it
     * has it) is put back. Keys the response does not name as once are let go.
     */
    withOnce(shared, once) {
        const decoded = P.decode(shared);
        const data = decoded instanceof Map ? new Map(decoded) : new Map();
        for (const [key, expires] of Object.entries(once)) {
            if (data.has(key)) {
                this.onceShared.set(key, { value: data.get(key), expires: expires === null ? null : expires * 1000 });
            } else if (this.onceShared.has(key)) {
                data.set(key, this.onceShared.get(key).value);
            }
        }
        for (const key of [...this.onceShared.keys()]) {
            if (!(key in once)) this.onceShared.delete(key);
        }
        return data.size === 0 && !(decoded instanceof Map) ? shared : P.encode(data);
    }

    /** The once data the browser has and that has not expired: sent as X-Fusion-Once. */
    onceHeader() {
        const now = Date.now();
        return [...this.onceShared].filter(([, kept]) => kept.expires === null || kept.expires > now).map(([key]) => key).join(',');
    }

    /** Fusion::flash()'s data from a response: what reads it ("$flash") renders again, and fusion:flash announces it. */
    setFlash(flash, except = null) {
        if (JSON.stringify(flash) !== JSON.stringify(globals.flash)) {
            globals.flash = flash;
            for (const component of this.components()) {
                component.dirty.add('$flash');
                if (component !== except) component.update();
            }
        }
        this.announceFlash(flash);
    }

    announceFlash(flash) {
        const data = P.decode(flash ?? []);
        if (data instanceof Map && data.size > 0) this.emit('fusion:flash', { flash: plain(data) });
    }

    /**
     * $loadMore('payments'): the next page of a $this->scroll() prop, merged into it (a partial
     * reload of the page's URL with the paginator's page in the query). Nothing when it has no next.
     */
    loadMore(component, name) {
        if (!component.definition.page) {
            const error = new Error(`Fusion: [${component.name}] is a component; only pages load more of their properties`);
            this.report(component, error);
            return Promise.reject(error);
        }
        return this.network.reload(component, [name], false, () => {
            const value = component.data.get(name);
            const scroll = value instanceof Map ? value.get('scroll') : null;
            const next = scroll instanceof Map ? scroll.get('nextPage') : null;
            if (next === null || next === undefined) return null;
            const url = new URL(this.window.location.href);
            url.searchParams.set(P.toStr(scroll.get('pageName') ?? 'page'), P.toStr(next));
            return url.href;
        });
    }

    setShared(shared, except = null) {
        if (JSON.stringify(shared) === JSON.stringify(globals.shared)) return;
        globals.shared = shared;
        for (const component of this.components()) {
            component.dirty.add('$shared');
            if (component !== except) component.update();
        }
    }

    /** What a #[Server] call asked for besides the new state. */
    effects(component, effects) {
        for (const { event, params, to, self } of effects.dispatches ?? []) {
            this.dispatch(event, P.decode(params), component, { to, self });
        }
        for (const code of effects.js ?? []) {
            component.js(code);
        }
        if (effects.reload) this.reload(component, effects.reload.only).catch(() => {});
        if (effects.redirect) {
            effects.redirect.navigate ? this.navigator.visit(effects.redirect.url) : (this.window.location.href = effects.redirect.url);
        }
    }

    /**
     * $this->dispatch('saved', ...): the listening components (#[On]) and the window; with
     * ->to('name') the components of that name only, with ->self() the one dispatching it.
     */
    dispatch(event, params, source, target = {}) {
        const args = P.entries(params ?? []).map(([, v]) => v);
        for (const component of this.components()) {
            if (target.to && component.name !== target.to) continue;
            if (target.self && component !== source) continue;
            for (const method of component.definition.listeners[event] ?? []) {
                if (component.definition.server.includes(method)) {
                    component.ctx.$call(method, args).catch((error) => this.report(component, error));
                } else {
                    try {
                        component.ctx[method](...args);
                    } catch (error) {
                        this.report(component, error);
                    }
                }
            }
        }
        if (!target.to && !target.self) this.emit(event, { params: args, component: source?.name ?? null });
    }

    emit(name, detail) {
        this.window.dispatchEvent(new this.window.CustomEvent(name, { detail }));
    }

    report(component, error) {
        this.window.console.error(component ? `Fusion [${component.name}]:` : 'Fusion:', error);
        this.emit('fusion:error', {
            component: component?.name ?? null,
            message: String(error?.message ?? error),
            ...(error?.status !== undefined ? { status: error.status, body: error.body } : {}),
        });
    }
}

/** The component's name in a signed envelope (JSON or parsed), null when it cannot be read. */
function nameIn(envelope) {
    try {
        return readState(typeof envelope === 'string' ? JSON.parse(envelope) : envelope).name ?? null;
    } catch {
        return null;
    }
}

/** The server-rendered components under root (root included): their elements, with state. */
function snapshotsIn(root) {
    return [...(root.matches?.('[fusion\\:snapshot]') ? [root] : []), ...root.querySelectorAll('[fusion\\:snapshot]')];
}

/** A PHP value as plain JavaScript (Maps as objects), for event listeners. */
function plain(value) {
    if (value instanceof Map) return Object.fromEntries([...value].map(([key, item]) => [key, plain(item)]));
    if (Array.isArray(value)) return value.map(plain);
    return value;
}
