/**
 * Fusion in the page: the compiled definitions (Fusion.define, from app.js and the #[LazyLoad]
 * chunks it loads when a page needs them), the components currently on the page, and the
 * services they share (events, models, network, navigation).
 */
import * as P from '../php.js';
import { Component, readState } from './component.js';
import { ErrorBag } from './errors.js';
import { Events } from './events.js';
import { Hooks } from './hooks.js';
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
        this.events = new Events(this, this.document);
        this.hooks = new Hooks(this, window);
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

    /** A chunk's components: loaded once (app/<group>.js, the bundle's version). */
    load(group) {
        if (!this.chunks.has(group)) {
            this.chunks.set(group, new Promise((resolve, reject) => {
                const script = this.document.createElement('script');
                script.src = `${this.config.endpoint}/app/${encodeURIComponent(group)}.js?v=${encodeURIComponent(this.config.bundle ?? '')}`;
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

    /** Take over the server-rendered components under root (once the chunks they need are here). */
    start(root = this.document.body) {
        if (!this.styled) {
            const style = this.document.createElement('style');
            style.textContent = STYLE;
            this.document.head.appendChild(style);
            this.styled = true;
        }

        const elements = [...(root.matches?.('[fusion\\:snapshot]') ? [root] : []), ...root.querySelectorAll('[fusion\\:snapshot]')];
        const take = () => {
            const created = elements.map((el) => this.hydrate(el)).filter(Boolean);
            created.forEach((component) => component.hydrate());
            /** mounted() once they are all live (a child's is there for its parent's). */
            created.forEach((component) => component.hook('mounted'));
            this.navigator.current();
        };

        /** @fusionScripts loads the chunks of the page it renders; this is for any it could not know of. */
        const waiting = this.require(elements.map((el) => nameIn(el.getAttribute('fusion:snapshot'))));
        if (!waiting) {
            take();
            return;
        }
        waiting.then(take, (error) => {
            this.report(null, error);
            take();
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
        const made = component.create((root) => {
            root.setAttribute('fusion:id', state.id);
            root.setAttribute('fusion:name', state.name);
            for (const [name, value] of Object.entries(attributes)) root.setAttribute(name, value);
            placeholder.replaceWith(root);
            this.instances.set(state.id, component);
        });
        if (!made) return null;
        component.hook('mounted');
        return component;
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

    /** What a #[Server] call asked for besides the new state. */
    effects(component, effects) {
        for (const { event, params, to, self } of effects.dispatches ?? []) {
            this.dispatch(event, P.decode(params), component, { to, self });
        }
        for (const code of effects.js ?? []) {
            component.js(code);
        }
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
