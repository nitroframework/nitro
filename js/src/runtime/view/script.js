/**
 * A view written for a JavaScript framework: Vue, React, Solid, or one of the app's own.
 *
 * The app's Vite builds the view; its engine, registered with Fusion.engine(name, { load, mount }),
 * loads it and mounts it in the element the server rendered. The component keeps the state, as
 * it does for a Blade view. The view reads and changes it through a handle, and is told what
 * changed. The adapters (js/adapters) build on this handle; an engine of the app's own may use
 * it directly.
 *
 *   load(view)                    the view's module, by its name ('cart/badge'): a promise
 *   mount(module, el, handle)     mounts it in el, and returns { update(names), unmount() }
 *
 * The handle speaks the PHP's names (an obfuscated build's aliases never reach the view) and
 * plain data (plain.js: a PHP array is an array or an object, and turns back into one):
 *
 *   handle.get(path)              a property's value ("form.email": a key under it), or a #[Computed] one
 *   handle.set(path, value)       writes a property, or a key under it, as fusion:model does
 *   handle.call(method, ...args)  a browser method runs here and returns its value; a #[Server]
 *                                 one returns a promise of its value
 *   handle.errors()               the validation messages, by field
 *   handle.unsaved()              the properties changed here that the server has not seen yet
 *   handle.dispatch(event, ...)   an event, as $this->dispatch() sends one
 *   handle.properties, .methods, .server, .computed    the names
 *   handle.component              the component itself, as Fusion.find() gives it
 *
 * update(names) is told what changed, by the PHP's names: "$errors" for the messages, "$unsaved"
 * when the server has taken the changes (nothing else may have changed then).
 */
import * as P from '../../php.js';
import { fromPlain, toPlain } from './plain.js';

export class ScriptView {
    constructor(runtime, engine, definition, $this, host) {
        this.runtime = runtime;
        this.engine = engine;
        this.definition = definition;
        this.$this = $this;
        this.host = host;
        this.el = null;
        this.mounted = null;
        this.disposed = false;
        this.handle = this.makeHandle();
    }

    /** The server rendered the element; the view mounts in it (server rendering is for later). */
    hydrate(el) {
        this.mount(el);
    }

    create() {
        const el = this.runtime.document.createElement('div');
        this.mount(el);
        return el;
    }

    update(names) {
        const byName = this.phpNames();
        this.notify([...names].map((name) => (name === '*' ? '*' : byName.get(name) ?? name)));
        return true;
    }

    /** The server has taken what changed here: what is unsaved is worked out again. */
    saved() {
        this.notify(['$unsaved']);
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

    mount(el) {
        this.el = el;
        Promise.resolve()
            .then(() => this.engine.load(this.definition.view))
            .then((module) => {
                if (this.disposed || this.el !== el) return;
                this.mounted = this.engine.mount(module, el, this.handle) ?? null;
            })
            .catch((error) => this.runtime.report(this.runtime.find(this.host.id), error));
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
            if (browser === undefined) throw new Error(`Fusion: [${root}] is not a property of this component`);
            return [browser, keys];
        };

        return {
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
                if (browser === undefined) throw new Error(`Fusion: [${method}] is not a method of this component`);
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
        };
    }
}
