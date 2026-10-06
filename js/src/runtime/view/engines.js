/**
 * View engines: what turns a component's state into its element. Blade's (View, the compiled
 * template) is built in. A JavaScript framework's (Vue, React, Solid) is added with
 * Fusion.engine(name, { load, mount }) and runs as a ScriptView (script.js). A component's
 * definition names its engine (engine: 'vue'); none is Blade.
 *
 * Every view has:
 *
 *   hydrate(el)      the server's HTML taken over: el is the root element
 *   create()         a new root element (a child made in the browser, a page from a visit)
 *   update(names)    the properties that changed ("*": all); false when the root must be made again
 *   refreshSlots()   the parent passed new slots
 *   teleported()     the top elements of its @teleport content, wherever they went
 *   dispose()        its component left the page
 */
import { ScriptView } from './script.js';
import { View } from './view.js';

export class Engines {
    constructor(runtime) {
        this.runtime = runtime;
        this.engines = new Map();
    }

    add(name, engine) {
        if (name === 'blade' || this.engines.has(name)) throw new Error(`Fusion.engine('${name}') is registered already`);
        if (typeof engine?.load !== 'function' || typeof engine?.mount !== 'function') {
            throw new Error(`Fusion.engine('${name}', { load, mount }): load(view) gives the view's module, mount(module, el, handle) mounts it`);
        }
        this.engines.set(name, engine);
    }

    view(definition, $this, host, document) {
        const name = definition.engine ?? 'blade';
        if (name === 'blade') return new View(definition, $this, host, document);
        const engine = this.engines.get(name);
        if (!engine) throw new Error(`Fusion: no view engine [${name}]: register it with Fusion.engine('${name}', { load, mount })`);
        return new ScriptView(this.runtime, engine, definition, $this, host);
    }
}
