/**
 * React views for Fusion components: #[View(react: 'post-editor')] on the PHP class, and
 * resources/views/react/post-editor.jsx (or .tsx) as its view. Your Vite builds them; register
 * them once in your JavaScript:
 *
 *     import { fusionReact } from 'nitro-fusion/react';
 *
 *     fusionReact(import.meta.glob('../views/react/**\/*.{jsx,tsx}'));
 *
 * The view is a React component, and gets the Fusion component as its props, by the PHP's names:
 *
 *     export default function PostEditor({ title, count, doubled, increment, save, set, errors }) {
 *         return <>
 *             <input value={title} onChange={(e) => set('title', e.target.value)} />
 *             <button onClick={() => increment(3)}>{count} / {doubled}</button>
 *             <button onClick={save} disabled={save.processing}>Save</button>
 *             {errors.title?.[0]}
 *         </>;
 *     }
 *
 * Properties and #[Computed] values are plain data; methods are functions (a browser one runs
 * here, compiled from the PHP; a #[Server] one returns a promise of its value and carries
 * processing, failed and error); set(path, value) writes a property or a key under it ("form.email");
 * errors holds the validation messages by field; dispatch(event, ...params) sends an event.
 * useFusion() gives the same to any component inside the view.
 */
import { createContext, createElement, useContext, useSyncExternalStore } from 'react';
import { createRoot } from 'react-dom/client';

const FusionContext = createContext(null);

/**
 * Register the React engine with Fusion: `views` is what import.meta.glob() gives (loaders, or
 * modules with { eager: true }). `wrap(element)` may put providers around each view.
 */
export function fusionReact(views, { wrap } = {}) {
    const register = (Fusion) => Fusion.engine('react', engine(views, wrap));
    if (window.Fusion?.engine) register(window.Fusion);
    else document.addEventListener('fusion:starting', ({ detail }) => register(detail.Fusion), { once: true });
}

/** Inside a Fusion component's React view: the component, as its view gets it in its props. */
export function useFusion() {
    const store = useContext(FusionContext);
    if (!store) throw new Error('Fusion: useFusion() is for the view of a Fusion component (#[View(react: ...)])');
    return useSyncExternalStore(store.subscribe, store.snapshot);
}

function engine(views, wrap) {
    return {
        load(name) {
            const key = Object.keys(views).find((path) => /\.(jsx|tsx|js|ts)$/.test(path) && (path.replace(/\.\w+$/, '').endsWith(`/${name}`) || path.replace(/\.\w+$/, '') === name));
            if (key === undefined) throw new Error(`Fusion: no React view [${name}] among those fusionReact() was given`);
            const entry = views[key];
            return Promise.resolve(typeof entry === 'function' ? entry() : entry).then((module) => module.default ?? module);
        },

        mount(View, el, handle) {
            const store = createStore(handle);
            const Root = () => createElement(View, useSyncExternalStore(store.subscribe, store.snapshot));
            const root = createRoot(el);
            const view = createElement(FusionContext.Provider, { value: store }, createElement(Root));
            root.render(wrap ? wrap(view) : view);

            return {
                update: (names) => store.changed(names),
                unmount: () => root.unmount(),
            };
        },
    };
}

/** The component as React reads it: a snapshot, made again when something changes. */
function createStore(handle) {
    const listeners = new Set();
    const values = Object.fromEntries(handle.properties.map((name) => [name, handle.get(name)]));
    const computedNames = new Set(handle.computed);

    const functions = {};
    for (const name of handle.methods.filter((method) => !computedNames.has(method))) {
        functions[name] = (...args) => handle.call(name, ...args);
    }
    for (const name of handle.server) {
        const status = { processing: false, failed: false, error: null };
        const call = (...args) => {
            Object.assign(status, { processing: true, failed: false, error: null });
            publish();
            return handle.call(name, ...args).then(
                (value) => {
                    status.processing = false;
                    publish();
                    return value;
                },
                (error) => {
                    Object.assign(status, { processing: false, failed: true, error: String(error?.message ?? error) });
                    publish();
                    throw error;
                },
            );
        };
        for (const key of ['processing', 'failed', 'error']) Object.defineProperty(call, key, { get: () => status[key] });
        functions[name] = call;
    }

    /** set(path, value): written at once, so a controlled input keeps its caret. */
    functions.set = (path, value) => {
        handle.set(path, value);
        const root = String(path).split('.')[0];
        values[root] = handle.get(root);
        publish();
    };
    functions.dispatch = (event, ...params) => handle.dispatch(event, ...params);

    let current = null;
    const build = () => ({
        ...values,
        ...Object.fromEntries(handle.computed.map((name) => [name, handle.get(name)])),
        ...functions,
        errors: handle.errors(),
    });
    function publish() {
        current = build();
        listeners.forEach((listener) => listener());
    }
    current = build();

    return {
        subscribe(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        snapshot: () => current,
        changed(names) {
            for (const name of names.includes('*') ? Object.keys(values) : names) {
                if (name in values) values[name] = handle.get(name);
            }
            publish();
        },
    };
}
