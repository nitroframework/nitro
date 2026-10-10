/**
 * A component as JavaScript outside its view reaches it: a JavaScript framework's view
 * (view/script.js), an app's nitro: attribute (directives.js). By the PHP's names (an obfuscated
 * build's aliases never reach that code) and in plain data (view/plain.js: a PHP array is an
 * array or an object, and turns back into one):
 *
 *   handle.get(path)              a property's value ("form.email": a key under it), or a #[Computed] one
 *   handle.set(path, value)       writes a property, or a key under it, as nitro:model does
 *   handle.watch(path, fn)        fn(value) whenever the value changes after this (the server's
 *                                 answer, a reset, another input or library; not this handle's
 *                                 own set()); returns a function that stops watching
 *   handle.call(method, ...args)  a browser method runs here and returns its value; a #[Server]
 *                                 one returns a promise of its value
 *   handle.errors()               the validation messages, by field
 *   handle.unsaved()              the properties changed here that the server has not seen yet
 *   handle.dispatch(event, ...)   an event, as $this->dispatch() sends one
 *   handle.upload(path, files, progress)
 *                                 files into an Upload (or #[Uploads]) property, as nitro:model
 *                                 does for a file input: a promise of its new value
 *   handle.id, handle.el          the component's id on the page, and its root element
 *   handle.properties, .methods, .server, .computed    the names
 *   handle.component              the component itself, as Nitro.find() gives it
 *
 * The names come with the component's definition (names: PHP name => the browser's), which the
 * compiler writes for a component whose JavaScript needs them. Each handle is its own: give each
 * piece of code one (a view, each run of a nitro: attribute).
 */
import * as P from '../../php.js';
import { fromPlain, toPlain } from '../view/plain.js';
import { send } from '../server/upload.js';

/** The PHP's names, by the browser's (an obfuscated build's aliases). */
export function phpNamesOf(definition) {
    return new Map(Object.entries(definition.names?.properties ?? {}).map(([php, browser]) => [browser, php]));
}

/**
 * The handle on the component with this id, whose definition and $this (its context) are given.
 *
 * @param {object} runtime
 * @param {object} definition  the compiled definition, with its names
 * @param {object} $this       the component's context (component/context.js)
 * @param {string} id
 */
export function handleOf(runtime, definition, $this, id) {
    const names = definition.names ?? {};
    const browserName = (kind, php) => names[kind]?.[php];
    const component = () => runtime.find(id);
    const phpNames = phpNamesOf(definition);
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
    const valueAt = (browser, keys) => (keys.length ? P.getIn($this[browser], keys) : $this[browser]);
    /** Who watches: this handle, so that its own set() does not come back to it. */
    const owner = {};

    return {
        id,
        properties: Object.keys(names.properties ?? {}),
        methods: Object.keys(names.methods ?? {}),
        server: Object.keys(names.server ?? {}),
        computed: Object.keys(names.computed ?? {}),

        get component() {
            return component();
        },

        get el() {
            return component()?.el ?? null;
        },

        get(path) {
            if (browserName('computed', path) !== undefined) return toPlain($this.$computed(browserName('computed', path)));
            const [browser, keys] = property(path);
            return toPlain(valueAt(browser, keys));
        },

        set(path, value) {
            const [browser, keys] = property(path);
            $this.$set([browser, ...keys].join('.'), fromPlain(value, valueAt(browser, keys)));
            /** Its own write: its watchers know the value, so it does not come back to the code that set it. */
            component()?.watchers.seen(browser, owner);
        },

        watch(path, fn) {
            if (typeof fn !== 'function') throw new Error('Nitro: watch(path, fn) takes a function to call');
            const [browser, keys] = property(path);
            const watched = component();
            if (!watched) return () => {};
            return watched.watchers.add(browser, () => toPlain(valueAt(browser, keys)), fn, owner);
        },

        call(method, ...args) {
            const values = args.map((arg) => fromPlain(arg));
            if (browserName('server', method) !== undefined) return $this.$call(browserName('server', method), values).then(toPlain);
            const browser = browserName('methods', method);
            if (browser === undefined) throw new Error(`Nitro: [${method}] is not a method of this component`);
            /** A Blade view's component has in the browser the methods its view and hooks reach. */
            if (typeof $this[browser] !== 'function') throw new Error(`Nitro: ${method}() is not in the browser: its view never calls it (call it from the view, or mark it #[Server])`);
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
            const current = valueAt(browser, keys);
            const owner = component();
            if (!owner) return Promise.reject(new Error('Nitro: the component has left the page'));
            return send(runtime, owner, [browser, ...keys].join('.'), Array.from(files ?? []), { multiple: P.isArray(current), progress }).then((result) => {
                if (result.failed) throw new Error(`Nitro: uploading ${path}: ${result.message}`);
                if (result.refused) return null;
                return toPlain(valueAt(browser, keys));
            });
        },
    };
}
