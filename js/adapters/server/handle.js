/**
 * The handle a view renders from in the render service: what the browser's handle
 * (runtime/view/script.js) gives on the first render, made of the state the server sent
 * (View\ServerRendering\ViewState). The values come in the same wire format the browser's state
 * does, and are read the same way, so the server's HTML is the browser's first render.
 *
 * A render only reads: writing, calling a method or dispatching is the browser's, once it has
 * taken over. A view that calls a method while it renders fails here, and the browser renders it.
 */
import { decode } from '../../src/php/wire.js';
import { toPlain } from '../../src/runtime/view/plain.js';

export function handleFrom(state) {
    const plain = (values) => Object.fromEntries(Object.entries(values ?? {}).map(([name, value]) => [name, toPlain(decode(value))]));
    const properties = plain(state.properties);
    const computed = plain(state.computed);

    const get = (path) => {
        if (path in computed) return computed[path];
        const [root, ...keys] = String(path).split('.');
        if (!(root in properties)) throw new Error(`Fusion: [${root}] is not a property of this component`);
        return keys.reduce((value, key) => value?.[key], properties[root]);
    };

    const browserOnly = (what) => () => {
        throw new Error(`Fusion: ${what} while the view renders on the server: that is the browser's, once it has taken over`);
    };

    return {
        id: state.id,
        properties: Object.keys(properties),
        methods: state.methods ?? [],
        server: state.server ?? [],
        computed: Object.keys(computed),
        component: null,
        get,
        set: browserOnly('set()'),
        call: (method) => browserOnly(`${method}() was called`)(),
        errors: () => state.errors ?? {},
        unsaved: () => [],
        dispatch: browserOnly('dispatch()'),
        upload: browserOnly('upload()'),
    };
}
