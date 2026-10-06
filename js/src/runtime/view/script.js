/**
 * A view written for a JavaScript framework (Vue, React, Solid): the app's build compiles it; its
 * engine, registered with Fusion.engine(name, { load, mount }), loads it and mounts it in the
 * element the server rendered. The component keeps the state; the view reads it through a handle,
 * and hears what changed.
 *
 *   load(view)                  the view's module, by its name ('cart/badge'): a promise
 *   mount(module, el, handle)   mounts it in el; returns { update(names), unmount() } (both optional)
 *
 * The handle speaks the PHP's names (an obfuscated build's aliases stay inside) and plain data
 * (plain.js: a PHP array is an array or an object):
 *
 *   handle.get(path)            a property's value ("form.email": a key under it), or a #[Computed] one's
 *   handle.set(path, value)     a property, or a key under it, set as fusion:model sets one
 *   handle.call(method, ...args) a method: a browser one runs here and returns its value; a
 *                               #[Server] one returns a promise of its value
 *   handle.errors()             the validation messages, by field
 *   handle.dispatch(event, ...params)   an event, as $this->dispatch() sends one
 *   handle.properties, .methods, .server, .computed   the names
 *   handle.component            the component (as Fusion.find() gives it)
 *
 * update(names) gets what changed, by those names ($errors: the messages).
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

    hydrate(el) {
        this.mount(el);
    }

    create() {
        const el = this.runtime.document.createElement('div');
        this.mount(el);
        return el;
    }

    update(names) {
        if (this.mounted?.update) {
            const byName = this.phpNames();
            this.mounted.update([...names].map((name) => (name === '*' ? '*' : byName.get(name) ?? name)));
        }
        return true;
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

    /** The PHP's names by the browser's (an obfuscated build's aliases). */
    phpNames() {
        this.byName ??= new Map(Object.entries(this.definition.names?.properties ?? {}).map(([php, browser]) => [browser, php]));
        return this.byName;
    }

    makeHandle() {
        const names = this.definition.names ?? {};
        const name = (kind, php) => names[kind]?.[php];
        const $this = this.$this;
        const runtime = this.runtime;
        const id = this.host.id;
        const component = () => runtime.find(id);
        const property = (path) => {
            const [root, ...keys] = String(path).split('.');
            const browser = name('properties', root);
            if (browser === undefined) throw new Error(`Fusion: [${root}] is not a property of this component`);
            return [browser, keys];
        };
        const phpNames = this.phpNames();

        return {
            properties: Object.keys(names.properties ?? {}),
            methods: Object.keys(names.methods ?? {}),
            server: Object.keys(names.server ?? {}),
            computed: Object.keys(names.computed ?? {}),
            get component() {
                return component();
            },
            /** A property's value ("form.email": a key under it), or a #[Computed] one's, as plain data. */
            get(path) {
                if (name('computed', path) !== undefined) return toPlain($this.$computed(name('computed', path)));
                const [browser, keys] = property(path);
                return toPlain(keys.length ? P.getIn($this[browser], keys) : $this[browser]);
            },
            /** Set a property, or a key under it ("form.email"), from plain data. */
            set(path, value) {
                const [browser, keys] = property(path);
                const current = keys.length ? P.getIn($this[browser], keys) : $this[browser];
                $this.$set([browser, ...keys].join('.'), fromPlain(value, current));
            },
            /** A browser method runs here and returns its value; a #[Server] one returns a promise of it. */
            call(method, ...args) {
                const values = args.map((arg) => fromPlain(arg));
                if (name('server', method) !== undefined) return $this.$call(name('server', method), values).then(toPlain);
                const browser = name('methods', method);
                if (browser === undefined) throw new Error(`Fusion: [${method}] is not a method of this component`);
                return toPlain($this[browser](...values));
            },
            /** The validation messages, by field ("form.email"). */
            errors() {
                const messages = {};
                for (const [field, list] of component()?.errors.messages ?? []) {
                    const [root, ...keys] = field.split('.');
                    messages[[phpNames.get(root) ?? root, ...keys].join('.')] = [...list];
                }
                return messages;
            },
            /** An event, as $this->dispatch() sends one. */
            dispatch(event, ...params) {
                component()?.dispatch(event, params.map((param) => fromPlain(param)));
            },
        };
    }
}
