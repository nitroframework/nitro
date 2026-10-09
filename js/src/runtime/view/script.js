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
 * The handle speaks the PHP's names (an obfuscated build's aliases never reach the view) and
 * plain data (plain.js: a PHP array is an array or an object, and turns back into one):
 *
 *   handle.get(path)              a property's value ("form.email": a key under it), or a #[Computed] one
 *   handle.set(path, value)       writes a property, or a key under it, as nitro:model does
 *   handle.call(method, ...args)  a browser method runs here and returns its value; a #[Server]
 *                                 one returns a promise of its value
 *   handle.errors()               the validation messages, by field
 *   handle.unsaved()              the properties changed here that the server has not seen yet
 *   handle.dispatch(event, ...)   an event, as $this->dispatch() sends one
 *   handle.upload(path, files, progress)
 *                                 files into an Upload (or #[Uploads]) property, as nitro:model
 *                                 does for a file input: a promise of its new value
 *   handle.id                     the component's id on the page
 *   handle.properties, .methods, .server, .computed    the names
 *   handle.component              the component itself, as Nitro.find() gives it
 *
 * update(names) is told what changed, by the PHP's names: "$errors" for the messages, "$unsaved"
 * when the server has taken the changes (nothing else may have changed then).
 */
import * as P from '../../php.js';
import { fromPlain, toPlain } from './plain.js';
import { send } from '../server/upload.js';
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
        this.handle = this.makeHandle();
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
        this.byName ??= new Map(Object.entries(this.definition.names?.properties ?? {}).map(([php, browser]) => [browser, php]));
        return this.byName;
    }

    makeHandle() {
        const names = this.definition.names ?? {};
        const browserName = (kind, php) => names[kind]?.[php];
        const $this = this.$this;
        const runtime = this.runtime;
        const id = this.host.id;
        const component = () => runtime.find(id);
        const phpNames = this.phpNames();
        const phpPath = (path) => {
            const [root, ...keys] = String(path).split('.');
            return [phpNames.get(root) ?? root, ...keys].join('.');
        };
        const property = (path) => {
            const [root, ...keys] = String(path).split('.');
            const browser = browserName('properties', root);
            if (browser === undefined) throw new Error(`Nitro: [${root}] is not a property of this component`);
            return [browser, keys];
        };

        return {
            id,
            properties: Object.keys(names.properties ?? {}),
            methods: Object.keys(names.methods ?? {}),
            server: Object.keys(names.server ?? {}),
            computed: Object.keys(names.computed ?? {}),

            get component() {
                return component();
            },

            get(path) {
                if (browserName('computed', path) !== undefined) return toPlain($this.$computed(browserName('computed', path)));
                const [browser, keys] = property(path);
                return toPlain(keys.length ? P.getIn($this[browser], keys) : $this[browser]);
            },

            set(path, value) {
                const [browser, keys] = property(path);
                const current = keys.length ? P.getIn($this[browser], keys) : $this[browser];
                $this.$set([browser, ...keys].join('.'), fromPlain(value, current));
            },

            call(method, ...args) {
                const values = args.map((arg) => fromPlain(arg));
                if (browserName('server', method) !== undefined) return $this.$call(browserName('server', method), values).then(toPlain);
                const browser = browserName('methods', method);
                if (browser === undefined) throw new Error(`Nitro: [${method}] is not a method of this component`);
                return toPlain($this[browser](...values));
            },

            errors() {
                const messages = {};
                for (const [field, list] of component()?.errors.messages ?? []) messages[phpPath(field)] = [...list];
                return messages;
            },

            unsaved() {
                return (component()?.unsaved() ?? []).map(phpPath);
            },

            dispatch(event, ...params) {
                component()?.dispatch(event, params.map((param) => fromPlain(param)));
            },

            /**
             * Files into a property that takes them (an Upload, or #[Uploads] for a list: the
             * property's value says which), as nitro:model does for a file input. Resolves with
             * the property's new value; null when the server's rules refused them (errors() says
             * why); rejects when the request failed. progress(0-100) as it goes.
             */
            upload(path, files, progress) {
                const [browser, keys] = property(path);
                const current = keys.length ? P.getIn($this[browser], keys) : $this[browser];
                const owner = component();
                if (!owner) return Promise.reject(new Error('Nitro: the component has left the page'));
                return send(runtime, owner, [browser, ...keys].join('.'), Array.from(files ?? []), { multiple: P.isArray(current), progress }).then((result) => {
                    if (result.failed) throw new Error(`Nitro: uploading ${path}: ${result.message}`);
                    if (result.refused) return null;
                    return toPlain(keys.length ? P.getIn($this[browser], keys) : $this[browser]);
                });
            },
        };
    }
}
