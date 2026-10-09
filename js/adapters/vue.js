/**
 * Vue views for Nitro components.
 *
 * The PHP class names its view, #[View(vue: 'post-editor')], and resources/views/vue (or
 * resources/js/vue) holds post-editor.vue. Your Vite builds it; register the views once:
 *
 *     import { nitroVue } from 'nitro/vue';
 *
 *     nitroVue(import.meta.glob('../views/vue/**\/*.vue'));
 *
 * The template sees the component by its PHP names, as a Blade view does:
 *
 *     <input v-model="title">                         a property, two-way, nested keys too
 *     {{ count }} / {{ doubled }}                     properties and #[Computed] values
 *     <button @click="increment(3)">                  a browser method, compiled from the PHP
 *     <button @click="save" :disabled="save.processing">   a #[Server] method, and its call
 *     <p v-if="unsaved.length">Unsaved changes</p>    what the server has not seen yet
 *     {{ errors.title?.[0] }}                         the validation messages
 *
 * In <script setup>, useNitro() gives the same: properties and values as refs, the functions,
 * `errors` and `unsaved` as refs. The behaviour is the shared core's (core.js); this adapter
 * only makes it Vue's.
 *
 * Vue 3's public API only (createApp, createSSRApp, ref, reactive, computed, watch, effectScope,
 * inject, markRaw; vue/server-renderer's renderToString), so a Vue release changes nothing here.
 */
import { computed, createApp, createSSRApp, effectScope, inject, markRaw, reactive, ref, shallowRef, watch } from 'vue';
import { connect, register } from './core.js';

const KEY = Symbol('nitro');

/** Register the Vue engine with Nitro: `views` is what import.meta.glob() gives. `setup(app)` runs on each Vue app before it mounts (its plugins). */
export function nitroVue(views, { setup } = {}) {
    register('vue', views, (view, el, handle, options) => mount(view, el, handle, setup, options));
}

/** In a Nitro component's Vue view: the component, as refs and functions. */
export function useNitro() {
    const nitro = inject(KEY, null);
    if (!nitro) throw new Error('Nitro: useNitro() is for the view of a Nitro component (#[View(vue: ...)])');
    return nitro;
}

function mount(view, el, handle, setup, { hydrate }) {
    const { app, nitro, scope } = createView(view, handle, { setup, ssr: hydrate });
    /** An app made with createSSRApp() takes over the server's HTML in el rather than replacing it. */
    app.mount(el);

    return {
        update: (names) => nitro.component.changed(names),
        unmount() {
            app.unmount();
            scope.stop();
        },
    };
}

/**
 * The Vue app for a view and its component: in the browser, and in the render service
 * (adapters/server/vue.js), so both render the same. `ssr`: made to hydrate, or to render to a
 * string, with createSSRApp().
 */
export function createView(view, handle, { setup, ssr = false } = {}) {
    const scope = effectScope();
    const nitro = scope.run(() => bind(handle));
    /** The view with the component's names for its template: a copy, so its own setup() stays its own. */
    const app = (ssr ? createSSRApp : createApp)({ ...view, mixins: [...(view.mixins ?? []), nitro.mixin] });
    app.provide(KEY, nitro.api);
    setup?.(app);

    return { app, nitro, scope };
}

/** The component as Vue holds it: a ref per property, kept in step both ways; derived values that follow. */
function bind(handle) {
    const component = connect(handle, { status: (initial) => reactive(initial) });
    const raw = rawUploads(globalThis.Nitro?.P?.Upload);
    const tick = shallowRef(0);
    const values = {};
    let syncing = false;

    for (const name of handle.properties) {
        values[name] = ref(raw(handle.get(name)));
        /** What the view changes (v-model, an assignment, deep inside too) reaches the component at once. */
        watch(values[name], (value) => syncing || handle.set(name, value), { deep: true, flush: 'sync' });
    }

    const derived = (read) => computed(() => (tick.value, raw(read())));
    const computedValues = Object.fromEntries(handle.computed.map((name) => [name, derived(() => handle.get(name))]));
    const errors = derived(() => handle.errors());
    const unsaved = derived(() => handle.unsaved());
    const uploads = derived(() => component.uploads());

    component.subscribe((names) => {
        syncing = true;
        try {
            for (const name of names.includes('*') ? Object.keys(values) : names) {
                if (values[name]) values[name].value = raw(handle.get(name));
            }
        } finally {
            syncing = false;
        }
        tick.value++;
    });

    const { functions } = component;
    const api = { ...values, ...computedValues, ...functions, errors, unsaved, uploads };
    const server = new Set(handle.server);

    const mixin = {
        computed: {
            ...Object.fromEntries(Object.entries(values).map(([name, value]) => [name, { get: () => value.value, set: (next) => (value.value = next) }])),
            ...Object.fromEntries(Object.entries(computedValues).map(([name, value]) => [name, () => value.value])),
            /** As they are: Vue binds what is in methods, and a bound function loses save.processing. */
            ...Object.fromEntries([...server].map((name) => [name, () => functions[name]])),
            errors: () => errors.value,
            unsaved: () => unsaved.value,
            uploads: () => uploads.value,
        },
        methods: Object.fromEntries(Object.entries(functions).filter(([name]) => !server.has(name))),
    };

    return { component, api, mixin };
}

/** Uploads stay as Nitro has them (Vue would wrap them); enum cases are frozen, so Vue leaves them be. */
function rawUploads(Upload) {
    const raw = (value) => {
        if (Upload && value instanceof Upload) return markRaw(value);
        if (Array.isArray(value)) value.forEach(raw);
        else if (value !== null && typeof value === 'object' && Object.getPrototypeOf(value) === Object.prototype) Object.values(value).forEach(raw);
        return value;
    };
    return raw;
}
