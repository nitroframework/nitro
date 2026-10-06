/**
 * Fusion in the page: the compiled definitions (Fusion.define, from app.js and the #[LazyLoad]
 * chunks it loads when a page needs them), the components currently on the page, and the
 * services they share (events, models, network, navigation).
 */
import { globals } from '../laravel/laravel.js';
import * as P from '../php.js';
import { Chunks } from './chunks.js';
import { Component, readState } from './component/component.js';
import { Directives } from './directives.js';
import { Engines } from './view/engines.js';
import { Plugins } from './plugins.js';
import { ErrorBag } from './component/errors.js';
import { Events } from './dom/events.js';
import { Hooks } from './dom/hooks.js';
import { islandsIn, whenToStart } from './dom/islands.js';
import { Broadcasts } from './server/echo.js';
import { applyLoading, STYLE } from './dom/loading.js';
import { applyDirty, STYLE as DIRTY_STYLE } from './dom/dirty.js';
import { Model } from './dom/model.js';
import { Navigator, webUrl } from './navigation/navigate.js';
import { Network } from './server/network.js';
import { SharedData } from './component/shared.js';
import { Store } from './component/store.js';

/** sessionStorage: the page stale() loaded again, so it does not again. */
const STALE = 'fusion:stale';

export class Runtime {
    constructor(window, config) {
        this.window = window;
        this.document = window.document;
        this.config = config;
        this.definitions = new Map();
        /** Stores: their compiled definitions, the tab's instances, and the server's states of those not made yet. */
        this.storeDefinitions = new Map();
        this.stores = new Map();
        this.storeStates = new Map();
        /** #[Browser] classes: class => its compiled static methods, which browser code calls as Fusion.classes[class][method](). */
        this.classes = Object.create(null);
        /** #[LazyLoad] chunks, and the markup-first pages (chunks.js) */
        this.chunks = new Chunks(this);
        this.instances = new Map();
        this.inflight = new WeakMap();
        /** Signed state of the children with mount() or #[Server] methods the server created for the page being rendered, by id. */
        this.provided = new Map();
        /** Shared, once and flash data from the server (shared.js) */
        this.shared = new SharedData(this);
        this.events = new Events(this, this.document);
        this.hooks = new Hooks(this, window);
        this.directives = new Directives(this);
        this.engines = new Engines(this);
        this.plugins = new Plugins(this);
        /** #[On('echo:...')]: broadcast events, through the application's Laravel Echo. */
        this.broadcasts = new Broadcasts(this, window);
        this.model = new Model(this, this.document);
        this.network = new Network(this, config);
        this.navigator = new Navigator(this, window);
        this.bus = { dispatch: (event, params, source) => this.dispatch(event, params, source) };
        /** The page is loading again: its code is from another build (stale()). */
        this.reloading = false;
    }

    /** Called by app.js (and the chunks) for every compiled component. */
    define(name, definition) {
        this.definitions.set(name, definition);
    }

    /** Fusion.directive(name, directive): what runs a fusion:<name> attribute of the application's own (directives.js). */
    directive(name, directive) {
        this.directives.register(name, directive);
    }

    /** Fusion.engine(name, { load, mount }): a view engine (Vue, React, Solid) components can name (view/engines.js). */
    engine(name, engine) {
        this.engines.add(name, engine);
    }

    /** Fusion.use(plugin): a plugin, called with the API to hook into the runtime (plugins.js). */
    use(plugin) {
        this.plugins.use(plugin);
    }

    /** Fusion.visit(url): a fusion:navigate visit, as $visit() in a handler does. */
    visit(url) {
        return this.navigator.visit(String(url));
    }

    /** Fusion.store(name, definition): a store, from app.js. */
    store(name, definition) {
        this.storeDefinitions.set(name, definition);
    }

    /**
     * Stores' signed states from the server (the page, a visit's data): kept for the stores not
     * made yet. One already made keeps its own: it is what the page's components have been
     * changing, across visits.
     */
    takeStores(envelopes) {
        for (const [name, envelope] of Object.entries(envelopes ?? {})) {
            if (!this.stores.has(name)) this.storeStates.set(name, envelope);
        }
    }

    /** The tab's store of that name: made the first time a component asks, from the server's state if it sent one. */
    storeNamed(name) {
        if (!this.stores.has(name)) {
            const definition = this.storeDefinitions.get(name);
            if (!definition) throw new Error(`Fusion: store [${name}] is not compiled`);
            this.stores.set(name, new Store(this, definition, name, this.storeStates.get(name) ?? null));
            this.storeStates.delete(name);
            this.plugins.madeStore(this.stores.get(name));
        }
        return this.stores.get(name);
    }

    /**
     * Called by app.js with the #[LazyLoad] components (name => chunk) and the pages that render
     * them ([path pattern, chunk]).
     */
    lazy(components, routes) {
        this.chunks.lazy(components, routes);
    }

    /** Called by app.js with the markup-first pages ([path pattern, page]): a visit renders them at once. */
    markupPages(routes) {
        this.chunks.markupPages(routes);
    }

    /** The markup-first page a path shows, or null. */
    markupPage(path) {
        return this.chunks.markupPage(path);
    }

    /**
     * A markup-first visit: the page rendered at once in place of the current one, with its
     * properties' defaults, each counting as deferred until the data arrives (arrive()). Null when
     * it cannot render without its data; the visit then goes on as a data-first one.
     */
    renderEarly(current, name, definition) {
        /** A client page (#[Navigate('client')]) needs nothing from the server: it is complete as it renders. */
        const client = Boolean(definition.client);
        const data = new Map();
        for (const [prop, spec] of Object.entries(definition.props)) {
            if ('default' in spec) data.set(prop, P.decode(spec.default));
        }
        const id = 'f' + Math.random().toString(36).slice(2, 12).padEnd(10, '0');
        const state = { id, name, data, errors: new ErrorBag(), deferred: client ? [] : Object.keys(definition.props) };
        const placeholder = this.document.createElement('div');
        current.replaceWith(placeholder);
        this.forget(current);
        const component = this.create(placeholder, definition, state, null, { 'fusion:page': definition.markup }, {}, { early: !client });
        if (component === null) {
            placeholder.replaceWith(current);
            return null;
        }
        if (client) return component;
        component.sent = new Map(component.data);
        /** Its #[Server] calls wait for its signed state. */
        component.arrival = new Promise((resolve) => (component.arrived = resolve));
        this.network.queues.set(component, component.arrival);
        return component;
    }

    /**
     * The data of a page rendered early: its id, signed state, deferred properties and the
     * children only the server could make. What changed here meanwhile (typed, toggled) is kept.
     */
    arrive(component, page) {
        const state = readState(page.snapshot);
        this.instances.delete(component.id);
        component.id = state.id;
        component.el.setAttribute('fusion:id', state.id);
        this.instances.set(state.id, component);
        component.early = false;
        component.errors = state.errors;
        component.deferred = new Set(state.deferred);
        component.setScroll(state.scroll, true);
        component.dirty.add('$deferred');
        component.dirty.add('$errors');
        this.provided = new Map(Object.entries(page.children ?? {}));
        try {
            component.applyReload(page.snapshot, [], component.sent ?? new Map());
        } finally {
            this.provided.clear();
        }
        this.loadDeferred(component);
        component.arrived?.();
    }

    /**
     * The page's HTML is from another build than its code (a deploy, or in development files that
     * changed, while it loaded): the server says so for app.js and its chunks, the runtime when a
     * chunk does not load or a component on the page has no definition. The page loads again, once:
     * true when it does. The second time in a row it does not (the builds disagree for another
     * reason), nor without sessionStorage to remember it by: the caller reports what is wrong.
     */
    stale() {
        if (this.reloading) return true;
        const href = this.window.location.href;
        try {
            if (this.window.sessionStorage.getItem(STALE) === href) return false;
            this.window.sessionStorage.setItem(STALE, href);
        } catch {
            return false;
        }
        this.reloading = true;
        this.window.location.reload();
        return true;
    }

    /** The chunks the components need that are not here yet: a promise, or null when none are missing. */
    require(names) {
        return this.chunks.require(names);
    }

    /** A visit to the path: start loading the chunk of the page it renders, while its data loads. */
    preload(path) {
        this.chunks.preload(path);
    }

    /**
     * Take over the server-rendered components under root (once the chunks they need are here).
     * Islands with fusion:hydrate, and the components inside them, wait for their moment (not
     * once their page is gone: a fusion:navigate visit replaced it). Resolves when the page's
     * components are live.
     */
    start(root = this.document.body) {
        if (this.reloading) return Promise.resolve();
        /** The stores the page's components use, as the server made them (before any component is). */
        this.takeStores(globals.stores);
        if (!this.styled) {
            const style = this.document.createElement('style');
            style.textContent = STYLE + DIRTY_STYLE;
            this.document.head.appendChild(style);
            this.styled = true;
        }

        const islands = islandsIn(root);
        const elements = snapshotsIn(root).filter((el) => !islands.some((island) => island.contains(el)));

        const live = this.take(elements).then(() => {
            if (this.reloading) return;
            /** Live: a later stale() may load the page again. */
            try {
                this.window.sessionStorage.removeItem(STALE);
            } catch {
                // no storage: stale() does not reload
            }
            this.navigator.current();
            /** A reload: what #[Remember] properties had in this history entry. */
            this.restoreRemembered(this.window.history.state?.fusionRemember);
            /** The page's once data, kept for the responses after it; its flash data, announced. */
            this.shared.withOnce(globals.shared, globals.once ?? {});
            this.shared.announceFlash(globals.flash);
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
            /** The page's HTML and its code from different builds: the page loads again, once. */
            if (!this.stale()) this.report(null, new Error(`Fusion: component [${state.name}] is not compiled (is @fusionScripts on the page?)`));
            return null;
        }

        const component = new Component(this, definition, el, state.id, state.name, state.data, envelope, state.errors);
        component.deferred = new Set(state.deferred);
        component.scroll = new Map(state.scroll ?? []);
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

            /** Created by the server for the page being rendered (mountPage()), with its signed state: one with mount() or #[Server] methods. */
            const envelope = this.provided.get(spec.id);
            const provided = envelope ? readState(envelope) : null;
            if (provided && provided.id === spec.id && provided.name === spec.name) {
                this.provided.delete(spec.id);
                this.create(placeholder, definition, provided, envelope, {}, spec.slots, { parent, model: spec.model ? { parent, path: spec.model } : null });
                continue;
            }

            /** A page rendered before its data: the server makes the child when the data comes. */
            if (parent.early && (definition.mount || definition.server.length > 0)) continue;

            if (definition.mount) {
                this.report(parent, new Error(`Fusion: [${spec.name}] has mount(), which runs on the server: it cannot be created in the browser. Render it on the server, or pass its data as properties instead of mount() parameters.`));
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
        /** A markup-first page rendered before its data (renderEarly()). */
        component.early = links.early ?? false;
        component.deferred = new Set(state.deferred ?? []);
        component.scroll = new Map(state.scroll ?? []);
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
        /** Rendered early: the visit's answer brings the data. */
        if (component.early) return;
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
        applyDirty(component);
        this.hooks.sync(component);
        this.directives.sync(component);
        this.plugins.rendered(component);
    }

    /** The state the server last signed changed (a response, a reload): fusion:dirty follows. */
    unsavedChanged(component) {
        if (!component.destroyed && component.el && !component.isStore) applyDirty(component);
    }

    loading(component, method, on) {
        const methods = this.inflight.get(component) ?? new Set();
        const name = method ?? '$refresh';
        on ? methods.add(name) : methods.delete(name);
        this.inflight.set(component, methods);

        /** A store's call: fusion:loading in the components that use it, with their own calls. */
        if (component.isStore) {
            for (const user of component.subscribers.keys()) {
                applyLoading(user, new Set([...(this.inflight.get(user) ?? []), ...methods]));
            }
            return;
        }

        applyLoading(component, methods);
    }

    /** What a response brings besides state: shared, once and flash data (shared.js). */
    receive(answer, except = null, visit = false) {
        this.shared.receive(answer, except, visit);
    }

    /** The once data the browser has and that has not expired: sent as X-Fusion-Once. */
    onceHeader() {
        return this.shared.onceHeader();
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
            const next = component.nextPage(name);
            if (next === null) return null;
            const url = new URL(this.window.location.href);
            url.searchParams.set(P.toStr(component.scroll.get(name).get('pageName') ?? 'page'), P.toStr(next));
            return url.href;
        });
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
            const url = webUrl(effects.redirect.url, this.window.location.href);
            if (url) effects.redirect.navigate ? this.navigator.visit(url.href) : (this.window.location.href = url.href);
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

    /** A window event; with cancelable, whether no listener called preventDefault(). */
    emit(name, detail, cancelable = false) {
        return this.window.dispatchEvent(new this.window.CustomEvent(name, { detail, cancelable }));
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
