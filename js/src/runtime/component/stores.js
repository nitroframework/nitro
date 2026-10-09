/**
 * The tab's stores: their compiled definitions (Nitro.store, from app.js), the instances made so
 * far, and the server's signed states of those not made yet. One per name for the tab, kept
 * across nitro:navigate visits.
 */
import { Store } from './store.js';

export class Stores {
    constructor(runtime) {
        this.runtime = runtime;
        this.definitions = new Map();
        this.instances = new Map();
        this.states = new Map();
    }

    /** A store's compiled definition, from app.js. */
    define(name, definition) {
        this.definitions.set(name, definition);
    }

    /**
     * Stores' signed states from the server (the page, a visit's data): kept for the stores not
     * made yet. One already made keeps its own: it is what the page's components have been
     * changing, across visits.
     */
    take(envelopes) {
        for (const [name, envelope] of Object.entries(envelopes ?? {})) {
            if (!this.instances.has(name)) this.states.set(name, envelope);
        }
    }

    /** The tab's store of that name: made the first time a component asks, from the server's state if it sent one. */
    named(name) {
        if (!this.instances.has(name)) {
            const definition = this.definitions.get(name);
            if (!definition) throw new Error(`Nitro: store [${name}] is not compiled`);
            this.instances.set(name, new Store(this.runtime, definition, name, this.states.get(name) ?? null));
            this.states.delete(name);
            this.runtime.plugins.madeStore(this.instances.get(name));
        }
        return this.instances.get(name);
    }

    /** The store of that name, if it was made. */
    get(name) {
        return this.instances.get(name);
    }

    /** The stores made so far. */
    values() {
        return this.instances.values();
    }
}
