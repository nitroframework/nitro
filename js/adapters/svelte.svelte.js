/**
 * Svelte views for Fusion components (Svelte 5).
 *
 * The PHP class names its view, #[View(svelte: 'post-editor')], and resources/views/svelte (or
 * resources/js/svelte) holds post-editor.svelte. Your Vite builds it (@sveltejs/vite-plugin-svelte,
 * which also compiles this file: it uses runes); register the views once:
 *
 *     import { fusionSvelte } from 'nitro-fusion/svelte';
 *
 *     fusionSvelte(import.meta.glob('../views/svelte/**\/*.svelte'));
 *
 * The view gets the component as its props, by the PHP's names, reactive as any Svelte props:
 *
 *     <script>
 *         let { title, count, increment, save, set, unsaved, errors } = $props();
 *     </script>
 *
 *     <input value={title} oninput={(e) => set('title', e.currentTarget.value)}>
 *     <button onclick={() => increment(3)}>{count}</button>
 *     <button onclick={() => save()} disabled={save.processing}>Save</button>
 *     {#if unsaved.length}Unsaved changes{/if} {errors.title?.[0]}
 *
 * useFusion() gives the same to any component inside the view. The behaviour is the shared
 * core's (core.js); this adapter only makes it Svelte's: one $state object the view's props are
 * read from, changed in place as the component changes.
 *
 * Svelte's public API only (mount, hydrate, unmount, getContext, $state; svelte/server's render),
 * so a Svelte release changes nothing here.
 */
import { getContext, hydrate, mount, unmount } from 'svelte';
import { connect, register } from './core.js';

const KEY = Symbol('fusion');

/** Register the Svelte engine with Fusion: `views` is what import.meta.glob() gives. */
export function fusionSvelte(views) {
    register('svelte', views, ['svelte'], mountView);
}

/** Inside a Fusion component's Svelte view: the component, as its view gets it in its props. */
export function useFusion() {
    const fusion = getContext(KEY);
    if (!fusion) throw new Error('Fusion: useFusion() is for the view of a Fusion component (#[View(svelte: ...)])');
    return fusion;
}

function mountView(View, el, handle, { hydrate: fromServer }) {
    const { props, context, component } = view(handle);
    /** hydrate() takes over the server's HTML in el; mount() renders into an empty one. */
    const instance = (fromServer ? hydrate : mount)(View, { target: el, props, context });

    return { update: (names) => component.changed(names), unmount: () => unmount(instance) };
}

/**
 * A view's props and context, for its component: in the browser, and in the render service
 * (adapters/server/svelte.js), so both render the same.
 */
export function view(handle) {
    const component = connect(handle, { status: reactive });
    const props = reactive({ ...component.read(), ...component.functions });
    /** Changed in place, value by value: what reads a value that did not change is left alone. */
    component.subscribe(() => Object.assign(props, component.read()));

    return { props, context: new Map([[KEY, props]]), component };
}

/** A $state object: Svelte follows what reads it. */
function reactive(initial) {
    const value = $state(initial);
    return value;
}
