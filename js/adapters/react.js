/**
 * React views for Nitro components.
 *
 * The PHP class names its view, #[View(react: 'post-editor')], and resources/views/react (or
 * resources/js/react) holds post-editor.jsx. Your Vite builds it; register the views once:
 *
 *     import { nitroReact } from 'nitro/react';
 *
 *     nitroReact(import.meta.glob('../views/react/**\/*.{jsx,tsx}'));
 *
 * The view gets the component as its props, by the PHP's names:
 *
 *     export default function PostEditor({ title, count, increment, save, set, unsaved, errors }) {
 *         return <>
 *             <input value={title} onChange={(e) => set('title', e.target.value)} />
 *             <button onClick={() => increment(3)}>{count}</button>
 *             <button onClick={save} disabled={save.processing}>Save</button>
 *             {unsaved.length > 0 && 'Unsaved changes'} {errors.title?.[0]}
 *         </>;
 *     }
 *
 * useNitro() gives the same to any component inside the view. The behaviour is the shared
 * core's (core.js); this adapter only makes it React's: a store React subscribes to.
 *
 * React 18 and 19's public API only (createElement, createContext, useContext,
 * useSyncExternalStore; react-dom's flushSync, createRoot, hydrateRoot; react-dom/server's
 * renderToString), so a React release changes nothing here.
 */
import { createContext, createElement, useContext, useSyncExternalStore } from 'react';
import { flushSync } from 'react-dom';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { connect, engine as engineOf, register } from './core.js';

/** A namespace's views (a module's, a package's): namespaced('issues', import.meta.glob(...)). */
export { namespaced } from './core.js';

const NitroContext = createContext(null);

/** Register the React engine with Nitro: `views` is what import.meta.glob() gives. `wrap(element)` may put providers around each view. */
export function nitroReact(views, options) {
    register('react', engine(views, options));
}

/** The React engine, not registered: what lazy() (nitro/lazy) registers once React is loaded. */
export function engine(views, { wrap } = {}) {
    return engineOf('react', views, (View, el, handle, options) => mount(View, el, handle, wrap, options));
}

/** Inside a Nitro component's React view: the component, as its view gets it in its props. */
export function useNitro() {
    const store = useContext(NitroContext);
    if (!store) throw new Error('Nitro: useNitro() is for the view of a Nitro component (#[View(react: ...)])');
    return useSyncExternalStore(store.subscribe, store.snapshot, store.snapshot);
}

function mount(View, el, handle, wrap, { hydrate }) {
    const { element, store } = tree(View, handle, wrap);
    /**
     * hydrateRoot() takes over the server's HTML in el; createRoot() renders into an empty one,
     * at once (flushSync): React would otherwise render a frame later, after the page has shown
     * the empty element.
     */
    const root = hydrate ? hydrateRoot(el, element) : createRoot(el);
    if (!hydrate) flushSync(() => root.render(element));

    return {
        update: (names) => store.changed(names),
        unmount: () => root.unmount(),
    };
}

/**
 * The React element for a view and its component: in the browser, and in the render service
 * (adapters/server/react.js), so both render the same.
 */
export function tree(View, handle, wrap) {
    const store = snapshots(connect(handle));
    /** The server snapshot is the same one: what the server rendered is the browser's first render. */
    const Root = () => createElement(View, useSyncExternalStore(store.subscribe, store.snapshot, store.snapshot));
    const element = createElement(NitroContext.Provider, { value: store }, createElement(Root));

    return { element: wrap ? wrap(element) : element, store };
}

/** The component as React reads it: an immutable snapshot, made again whenever something changes. */
function snapshots(component) {
    const listeners = new Set();
    let current = { ...component.read(), ...component.functions };

    component.subscribe(() => {
        current = { ...component.read(), ...component.functions };
        listeners.forEach((listener) => listener());
    });

    return {
        subscribe(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        snapshot: () => current,
        changed: component.changed,
    };
}
