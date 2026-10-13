/**
 * Nitro in the page: the compiled definitions (Nitro.define, from app.js and the #[LazyLoad]
 * chunks it loads when a page needs them), the components currently on the page, and the
 * services they share (events, models, network, navigation).
 */
import { globals } from '../laravel/laravel.js';
import * as P from '../php.js';
import { Chunks } from './chunks.js';
import { Component } from './component/component.js';
import { readState } from './component/snapshot.js';
import { dropState, nameOf, stateOf, withState } from './page-data.js';
import { Placement } from './component/placement.js';
import { restoreRemembered } from './component/remember.js';
import { Stores } from './component/stores.js';
import { PageMount } from './navigation/page-mount.js';
import { Directives } from './directives.js';
import { Engines } from './view/engines.js';
import { defineView } from './view/calls.js';
import { Plugins } from './plugins.js';
import { HOOK, Hooks } from './hooks.js';
import { Events } from './dom/events.js';
import { Triggers } from './dom/triggers.js';
import { islandsIn, whenToStart } from './dom/islands.js';
import { Broadcasts } from './server/echo.js';
import { applyLoading, STYLE } from './dom/loading.js';
import { applyDirty, STYLE as DIRTY_STYLE } from './dom/dirty.js';
import { Model } from './dom/model.js';
import { Removals } from './dom/removals.js';
import { Navigator } from './navigation/navigate.js';
import { Network } from './server/network.js';
import { SharedData } from './component/shared.js';
import { ATTRIBUTES } from './protocol.js';

/** sessionStorage: the page stale() loaded again, so it does not again. */
const STALE = 'nitro:stale';

export class Runtime {
    constructor(window, config) {
        this.window = window;
        this.document = window.document;
        this.config = config;
        this.definitions = new Map();
        /** Stores: their compiled definitions, the tab's instances, and the server's states of those not made yet (component/stores.js). */
        this.stores = new Stores(this);
        /** #[Browser] classes: class => its compiled static methods, which browser code calls as Nitro.classes[class][method](). */
        this.classes = Object.create(null);
        /** #[LazyLoad] chunks, and the markup-first pages (chunks.js) */
        this.chunks = new Chunks(this);
        this.instances = new Map();
        this.inflight = new WeakMap();
        /** The children a render places, and components made in the browser (component/placement.js). */
        this.placement = new Placement(this);
        /** A visit's page mounted in place (navigation/page-mount.js). */
        this.pageMount = new PageMount(this);
        /** Shared, once and flash data from the server (shared.js) */
        this.shared = new SharedData(this);
        this.events = new Events(this, this.document);
        this.triggers = new Triggers(this, window);
        this.directives = new Directives(this);
        this.engines = new Engines(this);
        this.plugins = new Plugins(this);
        /** The runtime's moments, announced in one place (hooks.js): Nitro.hook(), plugins, window events. */
        this.hooks = new Hooks(this);
        /** #[On('echo:...')]: broadcast events, through the application's Laravel Echo. */
        this.broadcasts = new Broadcasts(this, window);
        this.model = new Model(this, this.document);
        /** Components whose element something else took off the page, let go (dom/removals.js). */
        this.removals = new Removals(this, window);
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

    /** Nitro.view(id, definition): a Blade component's view, compiled once, which the views using it call (view/calls.js). */
    view(id, definition) {
        defineView(id, definition);
    }

    /** Nitro.directive(name, directive): what runs a nitro:<name> attribute of the application's own (directives.js). */
    directive(name, directive) {
        this.directives.register(name, directive);
    }

    /** Nitro.engine(name, { load, mount }): a view engine (Vue, React, Solid) components can name (view/engines.js). */
    engine(name, engine) {
        this.engines.add(name, engine);
    }

    /** Nitro.use(plugin): a plugin, called with the API to hook into the runtime (plugins.js). */
    use(plugin) {
        this.plugins.use(plugin);
    }

    /** Nitro.hook(name, listener): a listener for one of the runtime's moments (hooks.js); a function that removes it. */
    hook(name, listener) {
        return this.hooks.hook(name, listener);
    }

    /** Nitro.visit(url): a nitro:navigate visit, as $visit() in a handler does. */
    visit(url) {
        return this.navigator.visit(String(url));
    }

    /** Nitro.store(name, definition): a store, from app.js. */
    store(name, definition) {
        this.stores.define(name, definition);
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

    /** The markup-first page a path shows, with the properties a client page takes from it ({ name, props }), or null. */
    markupMatch(path) {
        return this.chunks.markupMatch(path);
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

    /** The modules of the JavaScript views these components place: a promise once they are here, null for none (view/engines.js). */
    preloadViews(names) {
        return this.engines.preload(names);
    }

    /**
     * Take over the server-rendered components under root (once the chunks they need are here).
     * Islands with nitro:hydrate, and the components inside them, wait for their moment (not
     * once their page is gone: a nitro:navigate visit replaced it). Resolves when the page's
     * components are live.
     */
    start(root = this.document.body) {
        if (this.reloading) return Promise.resolve();
        /** The stores the page's components use, as the server made them (before any component is). */
        this.stores.take(globals.stores);
        if (!this.styled) {
            const style = this.document.createElement('style');
            style.textContent = STYLE + DIRTY_STYLE;
            this.document.head.appendChild(style);
            this.styled = true;
        }

        this.removals.watch();
        /** nitro:prefetch.visible links outside components too (the layout's). */
        this.navigator.prefetcher.watch(root);
        const islands = islandsIn(root);
        const elements = withState(root).filter((el) => !islands.some((island) => island.contains(el)));

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
            restoreRemembered(this, this.window.history.state?.nitroRemember);
            /** The page's once data, kept for the responses after it; its flash data, announced. */
            this.shared.withOnce(globals.shared, globals.once ?? {});
            this.shared.announceFlash(globals.flash);
        });

        for (const island of islands) {
            whenToStart(island, this.window, () => {
                if (!island.isConnected) return Promise.resolve();
                island.removeAttribute(ATTRIBUTES.hydrate);
                /** Not those of an island inside it that is still waiting. */
                const waiting = islandsIn(island);
                return this.take(withState(island).filter((el) => !waiting.some((inner) => inner.contains(el))));
            });
        }
        return live;
    }

    /**
     * Components now in the page: mounted() once they are all there (a child's is there for its
     * parent's), then their @nitroScript blocks, then their broadcast listeners, then their
     * deferred properties asked for.
     */
    live(components) {
        components.forEach((component) => component.hook('mounted'));
        components.forEach((component) => component.runScripts());
        components.forEach((component) => this.broadcasts.subscribe(component));
        components.forEach((component) => this.loadDeferred(component));
    }

    /**
     * Make the elements' components live, once the chunks they need are here: @nitroScripts
     * loads those of the page it renders; this is for any it could not know of (and islands').
     */
    take(elements) {
        const go = () => {
            const created = elements.map((el) => this.hydrate(el)).filter(Boolean);
            created.forEach((component) => component.hydrate());
            this.live(created);
        };

        const waiting = this.require(elements.map(nameOf));
        if (!waiting) {
            go();
            return Promise.resolve();
        }
        return waiting.then(go, (error) => {
            this.report(null, error);
            go();
        });
    }

    /** A page from nitro:navigate: the names of its components with state from the server. */
    pageComponents(page) {
        return [page.snapshot, ...Object.values(page.children ?? {})].map((envelope) => nameIn(envelope));
    }

    hydrate(el) {
        let envelope;
        let state;
        try {
            envelope = stateOf(el);
            state = readState(envelope);
        } catch (error) {
            this.report(null, new Error('Nitro: unreadable component state'));
            return null;
        }
        dropState(el);

        const definition = this.definitions.get(state.name);
        if (!definition) {
            /** The page's HTML and its code from different builds: the page loads again, once. */
            if (!this.stale()) this.report(null, new Error(`Nitro: component [${state.name}] is not compiled (is @nitroScripts on the page?)`));
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
     * component element around it, unless a view marked it as its own (nitroOwner): a parent's
     * slot content inside a child, content teleported elsewhere.
     */
    owner(el) {
        for (let node = el; node; node = node.parentElement) {
            if (node.nitroOwner !== undefined) return this.find(node.nitroOwner);
            if (node.hasAttribute?.(ATTRIBUTES.id)) return this.find(node.getAttribute(ATTRIBUTES.id));
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

    /** A page with deferred properties asks for them once it is shown. */
    loadDeferred(component) {
        /** Rendered early: the visit's answer brings the data. */
        if (component.early) return;
        if (component.deferred.size > 0) this.network.reload(component, [...component.deferred], true).catch(() => {});
    }

    /** $reload / $this->reload(): a page's properties, from its route (all of them when none are named). */
    reload(component, only = []) {
        if (!component.definition.page) {
            const error = new Error(`Nitro: [${component.name}] is a component; only pages reload their properties`);
            this.report(component, error);
            return Promise.reject(error);
        }
        return this.network.reload(component, only.map(P.toStr));
    }

    afterRender(component) {
        this.events.watch(component);
        this.navigator.prefetcher.watch(component.el);
        this.model.sync(component);
        applyLoading(component, this.inflight.get(component) ?? new Set());
        applyDirty(component);
        this.triggers.sync(component);
        this.directives.sync(component);
        this.hooks.rendered(component);
    }

    /** The state the server last signed changed (a response, a reload): nitro:dirty follows. */
    unsavedChanged(component) {
        if (component.destroyed || !component.el || component.isStore) return;
        applyDirty(component);
        component.view?.saved?.();
    }

    loading(component, method, on) {
        const methods = this.inflight.get(component) ?? new Set();
        const name = method ?? '$refresh';
        on ? methods.add(name) : methods.delete(name);
        this.inflight.set(component, methods);

        /** A store's call: nitro:loading in the components that use it, with their own calls. */
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

    /** The once data the browser has and that has not expired: sent as X-Nitro-Once. */
    onceHeader() {
        return this.shared.onceHeader();
    }

    /**
     * $loadMore('payments'): the next page of a $this->scroll() prop, merged into it (a partial
     * reload of the page's URL with the paginator's page in the query). Nothing when it has no next.
     */
    loadMore(component, name) {
        if (!component.definition.page) {
            const error = new Error(`Nitro: [${component.name}] is a component; only pages load more of their properties`);
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

    /** An event of the application's ($this->dispatch()) on the window. The runtime's own moments are hooks (hooks.js). */
    emit(name, detail) {
        return this.window.dispatchEvent(new this.window.CustomEvent(name, { detail }));
    }

    report(component, error) {
        this.window.console.error(component ? `Nitro [${component.name}]:` : 'Nitro:', error);
        this.hooks.announce(HOOK.error, {
            component: component ?? null,
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
