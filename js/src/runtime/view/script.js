/**
 * A view written for a JavaScript framework: Vue, React, Solid, or one of the app's own.
 *
 * The app's Vite builds the view; its engine, registered with Nitro.engine(name, { load, mount }),
 * loads it and mounts it in the element the server rendered. The component keeps the state, as
 * it does for a Blade view. The view reads and changes it through a handle, and is told what
 * changed. The adapters (js/adapters) build on this handle; an engine of the app's own may use
 * it directly.
 *
 *   load(view)                    the view's module, by its name ('cart/badge'): a promise
 *   mount(module, el, handle, { hydrate })
 *                                 mounts it in el, and returns { update(names), unmount() };
 *                                 hydrate: el holds the view's server-rendered HTML, to take over
 *
 * The handle (component/handle.js) speaks the PHP's names, in plain data: get, set, watch,
 * call, errors, unsaved, dispatch, upload.
 *
 * update(names) is told what changed, by the PHP's names: "$errors" for the messages, "$unsaved"
 * when the server has taken the changes (nothing else may have changed then).
 */
import { handleOf, phpNamesOf } from '../component/handle.js';
import { ATTRIBUTES, SIGNALS, VIEW_WRAPPER } from '../protocol.js';

export class ScriptView {
    constructor(runtime, views, engine, definition, $this, host) {
        this.runtime = runtime;
        this.views = views;
        this.engine = engine;
        this.definition = definition;
        this.$this = $this;
        this.host = host;
        this.el = null;
        this.mounted = null;
        this.disposed = false;
        this.handle = handleOf(runtime, definition, $this, host.id);
    }

    /**
     * The server rendered the element. With server rendering (nitro.ssr) the view's HTML is in
     * it (nitro:rendered): the engine hydrates it, taking over the elements that are there.
     * Without, it is empty, and the view mounts in it.
     */
    hydrate(el) {
        this.mount(el, { hydrate: el.hasAttribute(ATTRIBUTES.serverHtml) });
    }

    create() {
        const el = this.runtime.document.createElement(VIEW_WRAPPER);
        this.mount(el, { hydrate: false });
        return el;
    }

    update(names) {
        const byName = this.phpNames();
        this.notify([...names].map((name) => (name === '*' ? '*' : byName.get(name) ?? name)));
        return true;
    }

    /** The server has taken what changed here: what is unsaved is worked out again. */
    saved() {
        this.notify([SIGNALS.unsaved]);
    }

    refreshSlots() {}

    teleported() {
        return [];
    }

    dispose() {
        this.disposed = true;
        this.mounted?.unmount?.();
        this.mounted = null;
    }

    /**
     * The view in el. Its module loaded already (a visit loads the new page's first): in a
     * microtask, once its component is in place but before the browser paints, so the view
     * shows with the page. Otherwise once the module is here.
     */
    mount(el, options) {
        this.el = el;
        const { engine, view } = this.definition;
        const attach = (module) => {
            if (this.disposed || this.el !== el) return;
            try {
                this.mounted = this.engine.mount(module, el, this.handle, options) ?? null;
            } catch (error) {
                this.runtime.report(this.runtime.find(this.host.id), error);
            }
        };

        const module = this.views.loaded(engine, view);
        if (module !== undefined) {
            queueMicrotask(() => attach(module));
            return;
        }

        this.views.load(engine, view).then(attach, (error) => this.runtime.report(this.runtime.find(this.host.id), error));
    }

    notify(names) {
        this.mounted?.update?.(names);
    }

    /** The PHP's names, by the browser's (an obfuscated build's aliases). */
    phpNames() {
        this.byName ??= phpNamesOf(this.definition);
        return this.byName;
    }
}
