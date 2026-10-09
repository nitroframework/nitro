/**
 * Solid views for Nitro components.
 *
 * The PHP class names its view, #[View(solid: 'post-editor')], and resources/views/solid (or
 * resources/js/solid) holds post-editor.jsx. Your Vite builds it (vite-plugin-solid); register
 * the views once:
 *
 *     import { nitroSolid } from 'nitro/solid';
 *
 *     nitroSolid(import.meta.glob('../views/solid/**\/*.{jsx,tsx}'));
 *
 * The view gets the component as its props, by the PHP's names. As with any Solid props, read
 * them where they are used (props.count), not destructured:
 *
 *     export default function PostEditor(props) {
 *         return <>
 *             <input value={props.title} onInput={(e) => props.set('title', e.currentTarget.value)} />
 *             <button onClick={() => props.increment(3)}>{props.count}</button>
 *             <button onClick={props.save} disabled={props.save.processing}>Save</button>
 *             <Show when={props.unsaved.length}>Unsaved changes</Show> {props.errors.title?.[0]}
 *         </>;
 *     }
 *
 * useNitro() gives the same to any component inside the view. The behaviour is the shared
 * core's (core.js); this adapter only makes it Solid's: a store that changes only where the
 * component's state did, so only those parts of the page update.
 *
 * Solid's public API only (createComponent, createContext, useContext, mergeProps; solid-js/store's
 * createStore, createMutable, reconcile; solid-js/web's render, hydrate, renderToString), so a
 * Solid release changes nothing here.
 */
import { createComponent, createContext, mergeProps, useContext } from 'solid-js';
import { createMutable, createStore, reconcile } from 'solid-js/store';
import { hydrate, render } from 'solid-js/web';
import { connect, register } from './core.js';

const NitroContext = createContext(null);

/** Register the Solid engine with Nitro: `views` is what import.meta.glob() gives. */
export function nitroSolid(views) {
    register('solid', views, mount);
}

/** Inside a Nitro component's Solid view: the component, as its view gets it in its props. */
export function useNitro() {
    const nitro = useContext(NitroContext);
    if (!nitro) throw new Error('Nitro: useNitro() is for the view of a Nitro component (#[View(solid: ...)])');
    return nitro;
}

function mount(View, el, handle, { hydrate: fromServer }) {
    const { root, component } = view(View, handle);
    /**
     * hydrate() takes over the server's HTML in el (the views are built hydratable); render()
     * fills an empty one. Each view is its own Solid root, its hydration keyed by its component's
     * id (the render service's renderId), and Solid's page-wide hydration state is set up here
     * when the page has none (its generateHydrationScript()).
     */
    if (fromServer) globalThis._$HY ??= { events: [], completed: new WeakSet(), r: {}, fe() {} };
    const dispose = fromServer ? hydrate(root, el, { renderId: handle.id }) : render(root, el);

    return { update: (names) => component.changed(names), unmount: dispose };
}

/**
 * A view and its component, as Solid renders them: in the browser, and in the render service
 * (adapters/server/solid.js), so both render the same.
 */
export function view(View, handle) {
    const component = connect(handle, { status: (initial) => createMutable(initial) });
    const [values, setValues] = createStore(component.read());
    /** Changed in place, value by value: what stayed the same keeps its elements. */
    component.subscribe(() => setValues(reconcile(component.read())));
    const props = mergeProps(values, component.functions);

    const root = () => createComponent(NitroContext.Provider, {
        value: props,
        get children() {
            return createComponent(View, props);
        },
    });

    return { root, component };
}
