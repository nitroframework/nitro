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
 *         let { title = $bindable(), count, increment, save, unsaved, errors } = $props();
 *     </script>
 *
 *     <input bind:value={title}>
 *     <button onclick={() => increment(3)}>{count}</button>
 *     <button onclick={() => save()} disabled={save.processing}>Save</button>
 *     {#if unsaved.length}Unsaved changes{/if} {errors.title?.[0]}
 *
 * A $bindable property is written back: bind:value, deep too (bind:value={form.email}), or an
 * assignment. set(path, value) writes any property. useFusion() gives the same to any component
 * inside the view. The behaviour is the shared core's (core.js); this adapter only makes it
 * Svelte's: one $state object the view's props are read from, changed in place as the component
 * changes, and followed back to the component when the view changes it.
 *
 * Svelte's public API only (mount, hydrate, unmount, getContext, flushSync, $state, $effect.root; svelte/server's
 * render), so a Svelte release changes nothing here.
 */
import { flushSync, getContext, hydrate, mount, unmount } from 'svelte';
import { connect, register } from './core.js';

const KEY = Symbol('fusion');

/** Register the Svelte engine with Fusion: `views` is what import.meta.glob() gives. */
export function fusionSvelte(views) {
    register('svelte', views, mountView);
}

/** Inside a Fusion component's Svelte view: the component, as its view gets it in its props. */
export function useFusion() {
    const fusion = getContext(KEY);
    if (!fusion) throw new Error('Fusion: useFusion() is for the view of a Fusion component (#[View(svelte: ...)])');
    return fusion;
}

function mountView(View, el, handle, { hydrate: fromServer }) {
    const { props, context, component } = view(written(handle), settle);
    /** hydrate() takes over the server's HTML in el; mount() renders into an empty one. */
    const instance = (fromServer ? hydrate : mount)(View, { target: el, props, context });
    const stop = writeBack(props, handle, component);

    return {
        update: (names) => component.changed(names),
        unmount() {
            stop();
            unmount(instance);
        },
    };
}

/** What the view has bound, written back now (Svelte would run those effects after the current code). */
function settle() {
    try {
        flushSync();
    } catch {
        /** Called from inside an effect, where Svelte flushes on its own. */
    }
}

/**
 * The handle, with what the view has bound written back before a method runs, a file uploads or
 * an event goes: Svelte runs effects after the code that changed a value, and a handler may call
 * save() in the same breath as it sets a bound value.
 */
function written(handle) {
    const settled = (run) => (...args) => {
        settle();
        return run(...args);
    };

    return Object.create(handle, {
        call: { value: settled(handle.call.bind(handle)) },
        upload: { value: settled(handle.upload.bind(handle)) },
        dispatch: { value: settled(handle.dispatch.bind(handle)) },
    });
}

/**
 * What the view writes into its props reaches the component: a $bindable prop that bind:value
 * or an assignment changes (Svelte writes it into the props object, as for any mounted
 * component's), deep inside too. A property that holds what the component has is left alone, so
 * the component's own changes, put in the props, don't come back.
 */
function writeBack(props, handle, component) {
    return $effect.root(() => {
        for (const name of handle.properties) {
            $effect(() => {
                /** Read all the way down, so a change deep inside runs this again. */
                const held = JSON.stringify(props[name]);
                if (held !== JSON.stringify(handle.get(name))) component.functions.set(name, $state.snapshot(props[name]));
            });
        }
    });
}

/**
 * A view's props and context, for its component: in the browser, and in the render service
 * (adapters/server/svelte.js), so both render the same.
 */
export function view(handle, before = () => {}) {
    const component = connect(handle, { status: reactive });
    const props = reactive({ ...component.read(), ...component.functions });
    /** Changed in place, value by value: what reads a value that did not change is left alone. What the view bound goes first, so it is not overwritten. */
    component.subscribe(() => {
        before();
        Object.assign(props, component.read());
    });

    return { props, context: new Map([[KEY, props]]), component };
}

/** A $state object: Svelte follows what reads it. */
function reactive(initial) {
    const value = $state(initial);
    return value;
}
