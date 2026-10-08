/**
 * A store in the browser (extends Store on the server): state several components share, one per
 * tab, kept across fusion:navigate visits. Its methods are compiled like a component's, and run
 * with the store as $this; #[Server] ones are calls with its signed state, as a component's are.
 *
 * The components that use it (public Cart $cart) read it through their property: a change
 * renders again, in each of them, what reads that property, and works out their #[Computed]
 * values again. It has no element and no view.
 */
import * as P from '../../php.js';
import { ErrorBag } from './errors.js';
import { defineProperties } from './context.js';
import { defaultsOf, readState, sameValue, updatesOf } from './snapshot.js';

export class Store {
    /**
     * @param {object} runtime     the Fusion runtime
     * @param {object} definition  the compiled store (Fusion.store)
     * @param {string} name        its name ("stores::cart", or an obfuscated build's alias)
     * @param {object|null} envelope  its signed state from the server; null: its defaults
     */
    constructor(runtime, definition, name, envelope) {
        this.runtime = runtime;
        this.definition = definition;
        this.name = name;
        this.id = name;
        this.isStore = true;
        const state = envelope ? readState(envelope) : null;
        this.data = state ? new Map(state.data) : defaultsOf(definition);
        this.snapshot = envelope ?? null;
        this.serverData = new Map(this.data);
        this.errors = state?.errors ?? new ErrorBag();
        this.partials = [];
        this.memo = new Map();
        this.destroyed = false;
        /** component => the property holding this store: rendered again when it changes */
        this.subscribers = new Map();
        /** plugins' listeners (listen()) */
        this.listeners = new Set();
        this.notifying = false;
        this.ctx = this.context();
    }

    /** The $this of its compiled methods, and what components read through their property. */
    context() {
        const self = this;
        const ctx = Object.create(null);

        defineProperties(ctx, this);

        for (const [name, fn] of Object.entries(this.definition.methods)) {
            ctx[name] = (...args) => fn.apply(ctx, args);
        }

        Object.assign(ctx, {
            $call: (method, args) => self.runtime.network.call(self, method, args),
            $computed: (name) => self.computed(name),
            $dispatch: (event, params) => {
                const target = { to: null, self: false };
                queueMicrotask(() => self.runtime.dispatch(event, params, self, target));
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
            },
            $reset: (names) => self.reset(names),
            $js: () => {},
        });
        Object.defineProperty(ctx, '$errors', { get: () => self.errors });

        return ctx;
    }

    write(name, value, prop) {
        if (prop.locked) {
            throw new P.PhpError('Error', `$${name} is #[Locked]: it cannot change in the browser.`);
        }
        if (this.data.get(name) === value) return;
        this.data.set(name, value);
        this.changed();
    }

    computed(name) {
        if (!this.memo.has(name)) this.memo.set(name, this.ctx[name]());
        return this.memo.get(name);
    }

    /** $this->reset('items'): back to the defaults it was compiled with. */
    reset(names) {
        const initial = defaultsOf(this.definition);
        for (const name of names.length ? names : [...initial.keys()]) {
            if (initial.has(name) && !this.definition.props[name]?.locked) this.data.set(name, initial.get(name));
        }
        this.changed();
    }

    /** What reads it, in every component that uses it, renders again (once, after the change); then the plugins' listeners hear it. */
    changed() {
        this.memo.clear();
        if (this.notifying) return;
        this.notifying = true;
        queueMicrotask(() => {
            this.notifying = false;
            for (const [component, property] of this.subscribers) {
                if (component.destroyed) this.subscribers.delete(component);
                else component.changed(property);
            }
            for (const listener of this.listeners) {
                try {
                    listener();
                } catch (error) {
                    this.runtime.report(this, error);
                }
            }
        });
    }

    /** A plugin's listener (plugins.js): called after each change, once per change, wherever it came from. */
    listen(listener) {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    }

    subscribe(component, property) {
        this.subscribers.set(component, property);
    }

    unsubscribe(component) {
        this.subscribers.delete(component);
    }

    /** Values changed here since its last signed state: sent with the next call. */
    updates() {
        return updatesOf(this);
    }

    /**
     * Its state from the server (after a call: its own, or a component's that used it). What
     * changed here while the call ran stays changed.
     */
    applySnapshot(envelope, sent = new Map(this.data)) {
        const { data, errors } = readState(envelope);
        let changed = false;

        for (const [name, value] of data) {
            const current = this.data.get(name);
            if (this.data.has(name) && sent.get(name) !== current) continue;
            if (!sameValue(current, value)) {
                this.data.set(name, value);
                changed = true;
            }
        }

        this.snapshot = envelope;
        this.serverData = new Map(data);
        if (JSON.stringify([...errors.messages]) !== JSON.stringify([...this.errors.messages])) changed = true;
        this.errors = errors;
        if (changed) this.changed();
    }

    /** A store lasts as long as the tab: a component's calls (js effects) have nothing to run on. */
    js() {}
}
