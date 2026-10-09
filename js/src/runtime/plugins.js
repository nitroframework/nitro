/**
 * Plugins: Nitro.use(plugin) calls the plugin once with an API to listen to the runtime.
 *
 *     Nitro.use(({ on, directive }) => {
 *         on('request', ({ headers }) => { headers['X-Tenant'] = tenant(); });
 *         on('store', (store) => store.onChange(() => console.log(store.name, store.get('items'))));
 *     });
 *
 * Events, each on(name, listener) returning a function that removes the listener:
 *
 *  - component (component)           a component started in the page (its first render)
 *  - render (component)               after each render of a component
 *  - store (store)                    a store was made in this tab (once the page's components started)
 *  - request ({ component, method, headers })   before a component's request is sent: a #[Server] call (its
 *                                               method), a reload ($reload), an upload ($upload); headers may be added to
 *  - response ({ component, method, status, ms, bytes, serverMs, dbMs })   when its answer arrived (status 0:
 *                                               none came): how long it took, its size when known, and in
 *                                               development the server's time and its database's (Server-Timing)
 *
 * A component is { name, el, dispatch(event, ...params) }; a store { name, persist, sync,
 * get(property), values(), set(property, value), onChange(listener) }, its values JSON-safe as the server
 * sends them (an associative array as {"$m": [[key, value], ...]}); set() is the browser's own
 * change, which the server checks on the next call. A listener registered for component or store
 * hears those already there too. Property names are the browser's (an obfuscated build's aliases).
 *
 * Nitro's #[Persist] and #[Sync] are plugins (js/src/plugins).
 */
import * as P from '../php.js';

const EVENTS = ['component', 'render', 'store', 'request', 'response'];

export class Plugins {
    constructor(runtime) {
        this.runtime = runtime;
        this.used = new Set();
        /** event => Set of listeners */
        this.listeners = new Map(EVENTS.map((event) => [event, new Set()]));
        this.started = new WeakSet();
        this.facades = new WeakMap();
    }

    use(plugin) {
        if (typeof plugin !== 'function') throw new Error('Nitro.use(): a plugin is a function, called with the plugin API');
        if (this.used.has(plugin)) return;
        this.used.add(plugin);
        plugin({
            on: (event, listener) => this.on(event, listener),
            directive: (name, directive) => this.runtime.directive(name, directive),
        });
    }

    on(event, listener) {
        if (!this.listeners.has(event)) throw new Error(`Nitro.use(): there is no event "${event}" (${EVENTS.join(', ')})`);
        if (typeof listener !== 'function') throw new Error(`Nitro.use(): on('${event}', ...) takes a function`);
        this.listeners.get(event).add(listener);

        /** Registered after the page started: the components and stores already there. */
        if (event === 'component') {
            for (const component of this.runtime.components()) {
                if (this.started.has(component) && !component.destroyed) this.call(listener, component, this.facade(component));
            }
        }
        if (event === 'store') {
            for (const store of this.runtime.stores.values()) this.call(listener, store, this.facade(store));
        }

        return () => this.listeners.get(event).delete(listener);
    }

    /** After a component's render (runtime.afterRender): component the first time, then render. */
    rendered(component) {
        if (!this.started.has(component)) {
            this.started.add(component);
            this.emit('component', component, this.facade(component));
        }
        this.emit('render', component, this.facade(component));
    }

    /** A store made in this tab: heard once the render that made it is over (its values then set re-render as any change). */
    madeStore(store) {
        queueMicrotask(() => this.emit('store', store, this.facade(store)));
    }

    /** The headers a #[Server] call is sent with, as the request listeners leave them. */
    request(target, method, headers) {
        this.emit('request', target, { component: this.facade(target), method, headers });
        return headers;
    }

    response(target, method, status, timing = {}) {
        this.emit('response', target, { component: this.facade(target), method, status, ...timing });
    }

    emit(event, target, payload) {
        for (const listener of this.listeners.get(event)) this.call(listener, target, payload);
    }

    call(listener, target, payload) {
        try {
            listener(payload);
        } catch (error) {
            this.runtime.report(target, error);
        }
    }

    /** What plugins see of a component or a store. */
    facade(target) {
        if (!this.facades.has(target)) this.facades.set(target, target.isStore ? this.storeFacade(target) : this.componentFacade(target));
        return this.facades.get(target);
    }

    componentFacade(component) {
        const runtime = this.runtime;
        return Object.freeze({
            name: component.name,
            get el() {
                return component.el;
            },
            /** As $this->dispatch(): the page's #[On] methods hear it, and a window event too. */
            dispatch(event, ...params) {
                runtime.dispatch(String(event), params.map((param) => P.decode(param)), component);
            },
        });
    }

    storeFacade(store) {
        const props = store.definition.props;
        const known = (property) => {
            if (!(property in props)) throw new Error(`Nitro: the store ${store.name} has no property ${property}`);
        };
        return Object.freeze({
            name: store.name,
            persist: Object.freeze([...(store.definition.persist ?? [])]),
            sync: Object.freeze([...(store.definition.sync ?? [])]),
            get(property) {
                known(property);
                return P.encode(store.data.get(property) ?? null);
            },
            /** Every property's value, by name. */
            values() {
                return Object.fromEntries(Object.keys(props).map((property) => [property, P.encode(store.data.get(property) ?? null)]));
            },
            /** The browser's own change, as a store method's would be: a #[Locked] property refuses it. */
            set(property, value) {
                known(property);
                store.write(property, P.decode(value), props[property]);
            },
            onChange: (listener) => store.listen(listener),
        });
    }
}
