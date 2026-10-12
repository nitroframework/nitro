/**
 * Plugins: Nitro.use(plugin) calls the plugin once with an API to hook into the runtime.
 *
 *     Nitro.use(({ on, directive }) => {
 *         on('beforeRequest', ({ headers }) => { headers['X-Tenant'] = tenant(); });
 *         on('store.initialized', (store) => store.onChange(() => console.log(store.name, store.get('items'))));
 *     });
 *
 * on(hook, listener) is Nitro.hook(): the hooks and what each gives are in hooks.js; the names
 * before 2.x's hooks (component, render, store, request, response) are heard too.
 *
 * What plugins (and hooks' listeners) see: a component is { name, el, dispatch(event, ...params) };
 * a store { name, persist, sync, get(property), values(), set(property, value), onChange(listener) },
 * its values JSON-safe as the server sends them (an associative array as {"$m": [[key, value], ...]});
 * set() is the browser's own change, which the server checks on the next call. Property names are
 * the browser's (an obfuscated build's aliases).
 *
 * Nitro's #[Persist] and #[Sync] are plugins (js/src/plugins).
 */
import * as P from '../php.js';

export class Plugins {
    constructor(runtime) {
        this.runtime = runtime;
        this.used = new Set();
        this.facades = new WeakMap();
    }

    use(plugin) {
        if (typeof plugin !== 'function') throw new Error('Nitro.use(): a plugin is a function, called with the plugin API');
        if (this.used.has(plugin)) return;
        this.used.add(plugin);
        plugin({
            on: (hook, listener) => this.runtime.hooks.hook(hook, listener, 'Nitro.use(): on()'),
            directive: (name, directive) => this.runtime.directive(name, directive),
        });
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
