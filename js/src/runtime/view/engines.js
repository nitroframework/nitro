/**
 * View engines: what turns a component's state into its element. Blade's (View, the compiled
 * template) is built in. A JavaScript framework's (Vue, React, Solid) is added with
 * Nitro.engine(name, { load, mount }) and runs as a ScriptView (script.js). A component's
 * definition names its engine (engine: 'vue'); none is Blade.
 *
 * Every view has:
 *
 *   hydrate(el)      the server's HTML taken over: el is the root element (a JavaScript view's
 *                    is server-rendered with nitro.ssr, or empty for the view to mount in)
 *   create()         a new root element (a child made in the browser, a page from a visit)
 *   update(names)    the properties that changed ("*": all); false when the root must be made again
 *   refreshSlots()   the parent passed new slots
 *   teleported()     the top elements of its @teleport content, wherever they went
 *   dispose()        its component left the page
 *
 * A JavaScript view's module is loaded once and kept. A visit loads the modules of the views
 * the new page places before it shows the page (preload()), so they render with it rather than
 * filling in one by one after it.
 */
import { ScriptView } from './script.js';
import { View } from './view.js';
import { BLADE_ENGINE } from '../protocol.js';

export class Engines {
    constructor(runtime) {
        this.runtime = runtime;
        this.engines = new Map();
        /** 'engine:view' => { promise, module }: each view's module, loading or loaded. */
        this.modules = new Map();
    }

    add(name, engine) {
        if (name === BLADE_ENGINE || this.engines.has(name)) throw new Error(`Nitro.engine('${name}') is registered already`);
        if (typeof engine?.load !== 'function' || typeof engine?.mount !== 'function') {
            throw new Error(`Nitro.engine('${name}', { load, mount }): load(view) gives the view's module, mount(module, el, handle) mounts it`);
        }
        this.engines.set(name, engine);
    }

    view(definition, $this, host, document) {
        /** A view Nitro compiled names no engine: only a JavaScript framework's does. */
        const name = definition.engine;
        if (name === undefined) return new View(definition, $this, host, document);
        const engine = this.engines.get(name);
        if (!engine) throw new Error(`Nitro: no view engine [${name}]: register it with Nitro.engine('${name}', { load, mount })`);
        return new ScriptView(this.runtime, this, engine, definition, $this, host);
    }

    /** A view's module: a promise, loaded once (null for an engine that is not registered). */
    load(name, view) {
        const engine = this.engines.get(name);
        if (!engine) return null;
        const key = `${name}:${view}`;
        if (!this.modules.has(key)) {
            const entry = { module: undefined };
            entry.promise = Promise.resolve()
                .then(() => engine.load(view))
                .then((module) => (entry.module = module));
            /** A failed load is tried again next time (a network blip), and reported where the view mounts. */
            entry.promise.catch(() => this.modules.delete(key));
            this.modules.set(key, entry);
        }
        return this.modules.get(key).promise;
    }

    /** A view's module when it is loaded already: the view then mounts without waiting. */
    loaded(name, view) {
        return this.modules.get(`${name}:${view}`)?.module;
    }

    /**
     * Load the modules of the JavaScript views the components place: theirs, and those of the
     * children their Blade views place, all the way down (a child in an @if is loaded too: that
     * costs a request, not a render). A promise that settles once they are here; null for none.
     */
    preload(names) {
        const seen = new Set();
        const loads = [];
        const walk = (name) => {
            if (seen.has(name)) return;
            seen.add(name);
            const definition = this.runtime.definitions.get(name);
            if (!definition) return;
            if (definition.engine !== undefined) {
                const loading = this.load(definition.engine, definition.view);
                if (loading) loads.push(loading);
                return;
            }
            childrenIn(definition.template, walk);
        };
        names.forEach(walk);
        return loads.length ? Promise.allSettled(loads) : null;
    }
}

/** The components a compiled template places by name (<nitro:name />: a 'c' node), wherever they are in it. */
function childrenIn(node, found) {
    if (Array.isArray(node)) {
        node.forEach((item) => childrenIn(item, found));
    } else if (node !== null && typeof node === 'object') {
        if (node.t === 'c' && typeof node.c === 'string') found(node.c);
        for (const value of Object.values(node)) {
            if (value !== null && typeof value === 'object') childrenIn(value, found);
        }
    }
}
