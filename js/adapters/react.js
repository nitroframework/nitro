/**
 * React views for Fusion components.
 *
 * The PHP class names its view, #[View(react: 'post-editor')], and resources/views/react (or
 * resources/js/react) holds post-editor.jsx. Your Vite builds it; register the views once:
 *
 *     import { fusionReact } from 'nitro-fusion/react';
 *
 *     fusionReact(import.meta.glob('../views/react/**\/*.{jsx,tsx}'));
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
 * useFusion() gives the same to any component inside the view. The behaviour is the shared
 * core's (core.js); this adapter only makes it React's: a store React subscribes to.
 */
import { createContext, createElement, useContext, useSyncExternalStore } from 'react';
import { createRoot } from 'react-dom/client';
import { connect, register } from './core.js';

const FusionContext = createContext(null);

/** Register the React engine with Fusion: `views` is what import.meta.glob() gives. `wrap(element)` may put providers around each view. */
export function fusionReact(views, { wrap } = {}) {
    register('react', views, ['jsx', 'tsx', 'js', 'ts'], (View, el, handle) => mount(View, el, handle, wrap));
}

/** Inside a Fusion component's React view: the component, as its view gets it in its props. */
export function useFusion() {
    const store = useContext(FusionContext);
    if (!store) throw new Error('Fusion: useFusion() is for the view of a Fusion component (#[View(react: ...)])');
    return useSyncExternalStore(store.subscribe, store.snapshot);
}

function mount(View, el, handle, wrap) {
    const store = snapshots(connect(handle));
    const Root = () => createElement(View, useSyncExternalStore(store.subscribe, store.snapshot));
    const root = createRoot(el);
    const view = createElement(FusionContext.Provider, { value: store }, createElement(Root));
    root.render(wrap ? wrap(view) : view);

    return {
        update: (names) => store.changed(names),
        unmount: () => root.unmount(),
    };
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
