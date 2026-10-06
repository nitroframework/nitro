/**
 * The application's own fusion: attributes (fusion.attributes on the server), each run by the
 * Fusion.directive() of its name:
 *
 *     Fusion.directive('tooltip', ({ el, value, modifiers, component }) => {
 *         const tip = makeTooltip(el, value);
 *         return () => tip.remove();
 *     });
 *
 * The directive runs when its element appears, and again when the attribute's value or modifiers
 * change (the view renders the value, {{ }} and all); what it returns, a function, cleans up
 * before it runs again and when the element or its component goes. component.dispatch(event,
 * ...params) dispatches an event, as $this->dispatch() does: #[On] methods hear it.
 *
 * The compiled view marks the elements that have one (fusion:h-directive); after each render of a
 * component, its marked elements are matched against the directives registered.
 */
import * as P from '../php.js';

const NAME = /^[a-z][a-z0-9]*(-[a-z0-9]+)*$/;

export class Directives {
    constructor(runtime) {
        this.runtime = runtime;
        /** name => the directive */
        this.registered = new Map();
        /** element => Map(name => {component, value, modifiers, cleanup}) */
        this.bound = new Map();
        /** Components whose destruction cleans up their elements' directives. */
        this.watching = new WeakSet();
    }

    register(name, directive) {
        if (typeof name !== 'string' || !NAME.test(name)) {
            throw new Error(`Fusion.directive(): ${JSON.stringify(name)} is not a fusion: attribute name (lowercase words joined by hyphens)`);
        }
        if (typeof directive !== 'function') throw new Error(`Fusion.directive('${name}'): the directive is a function`);
        if (this.registered.has(name)) throw new Error(`Fusion.directive('${name}') is registered already`);
        this.registered.set(name, directive);

        /** Registered after the page started: the elements already there. */
        for (const component of this.runtime.components()) {
            if (!component.destroyed) this.sync(component);
        }
    }

    /** After a render of the component: its marked elements' directives started, updated or cleaned up. */
    sync(component) {
        for (const [el, bindings] of this.bound) {
            if (!el.isConnected) this.unbind(el, bindings);
        }
        if (this.registered.size === 0) return;

        for (const el of component.scan('[fusion\\:h-directive]')) {
            if (this.runtime.owner(el) !== component) continue;
            const found = this.attributes(el);
            const bindings = this.bound.get(el) ?? new Map();

            for (const [name, binding] of bindings) {
                if (!found.has(name)) this.cleanup(binding, name, bindings);
            }
            for (const [name, { value, modifiers }] of found) {
                const current = bindings.get(name);
                if (current && current.value === value && current.modifiers.join('.') === modifiers.join('.')) continue;
                if (current) this.cleanup(current, name, bindings);
                bindings.set(name, { component, value, modifiers, cleanup: this.run(name, el, value, modifiers, component) });
            }

            if (bindings.size) this.bound.set(el, bindings);
            else this.bound.delete(el);
        }

        if (!this.watching.has(component)) {
            this.watching.add(component);
            component.cleanups.push(() => {
                for (const [el, bindings] of this.bound) {
                    for (const [name, binding] of bindings) {
                        if (binding.component === component) this.cleanup(binding, name, bindings);
                    }
                    if (!bindings.size) this.bound.delete(el);
                }
            });
        }
    }

    /** fusion:<name>.<modifiers>="value" attributes of registered directives: name => {value, modifiers}. */
    attributes(el) {
        const found = new Map();
        for (const attribute of el.attributes) {
            if (!attribute.name.startsWith('fusion:')) continue;
            const [name, ...modifiers] = attribute.name.slice('fusion:'.length).split('.');
            if (this.registered.has(name) && !found.has(name)) found.set(name, { value: attribute.value, modifiers });
        }
        return found;
    }

    run(name, el, value, modifiers, component) {
        const self = this;
        try {
            const cleanup = this.registered.get(name)({
                el,
                name,
                value,
                modifiers,
                component: {
                    el: component.el,
                    name: component.name,
                    /** As $this->dispatch(): the page's #[On] methods hear it, and a window event too. */
                    dispatch(event, ...params) {
                        self.runtime.dispatch(String(event), params.map((param) => P.decode(param)), component);
                    },
                },
            });
            if (cleanup !== undefined && typeof cleanup !== 'function') {
                throw new Error(`Fusion.directive('${name}') returned ${typeof cleanup}: return nothing, or a function that cleans up`);
            }
            return cleanup ?? null;
        } catch (error) {
            this.runtime.report(component, error);
            return null;
        }
    }

    cleanup(binding, name, bindings) {
        bindings.delete(name);
        if (!binding.cleanup) return;
        try {
            binding.cleanup();
        } catch (error) {
            this.runtime.report(binding.component, error);
        }
    }

    unbind(el, bindings) {
        for (const [name, binding] of bindings) this.cleanup(binding, name, bindings);
        this.bound.delete(el);
    }
}
