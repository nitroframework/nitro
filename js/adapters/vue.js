/**
 * Vue views for Fusion components: #[View(vue: 'cart/badge')] on the PHP class, and
 * resources/views/vue/cart/badge.vue as its view. Your Vite builds the .vue files; register them
 * once in your JavaScript:
 *
 *     import { fusionVue } from '../../vendor/nitro/fusion/js/adapters/vue.js';
 *
 *     fusionVue(import.meta.glob('../views/vue/**\/*.vue'));
 *
 * In the view's template, the component's properties, #[Computed] values and methods are there by
 * their PHP names: {{ count }}, v-model="title", @click="increment(2)", @click="save". A #[Server]
 * method carries its call's status: save.processing, save.failed, save.error. `errors` holds the
 * validation messages by field. In <script setup>, useFusion() gives the same: properties and
 * #[Computed] values as refs, the methods as functions, `errors`, and `dispatch(event, ...params)`.
 *
 * The component keeps the state: a browser method runs in the browser (compiled from the PHP),
 * a #[Server] one on the server; what changes shows in the view.
 */
import { computed, createApp, effectScope, inject, markRaw, reactive, ref, shallowRef, watch } from 'vue';

const KEY = Symbol('fusion');

/**
 * Register the Vue engine with Fusion: `views` is what import.meta.glob() gives (loaders, or
 * modules with { eager: true }). `setup(app)` runs on each Vue app before it mounts (plugins).
 */
export function fusionVue(views, { setup } = {}) {
    const register = (Fusion) => Fusion.engine('vue', engine(views, setup, Fusion));
    if (window.Fusion?.engine) register(window.Fusion);
    else document.addEventListener('fusion:starting', ({ detail }) => register(detail.Fusion), { once: true });
}

/** In a Fusion component's Vue view: its properties and #[Computed] values (refs), methods, errors, dispatch(). */
export function useFusion() {
    const fusion = inject(KEY, null);
    if (!fusion) throw new Error('Fusion: useFusion() is for the view of a Fusion component (#[View(vue: ...)])');
    return fusion;
}

function engine(views, setup, Fusion) {
    return {
        load(name) {
            const key = Object.keys(views).find((path) => path.endsWith(`/${name}.vue`) || path === `${name}.vue`);
            if (key === undefined) throw new Error(`Fusion: no Vue view [${name}] among those fusionVue() was given`);
            const entry = views[key];
            return Promise.resolve(typeof entry === 'function' ? entry() : entry).then((module) => module.default ?? module);
        },

        mount(view, el, handle) {
            const scope = effectScope();
            const fusion = scope.run(() => state(handle, Fusion));
            /** The view with the names its template may use bare (a copy: its setup() stays its own, which extends would not keep). */
            const app = createApp({ ...view, mixins: [...(view.mixins ?? []), fusion.mixin] });
            app.provide(KEY, fusion.api);
            setup?.(app);
            app.mount(el);

            return {
                update: (names) => fusion.update(names),
                unmount() {
                    app.unmount();
                    scope.stop();
                },
            };
        },
    };
}

/** The component's state as Vue holds it, kept in step with the component both ways. */
function state(handle, Fusion) {
    const values = {};
    const tick = shallowRef(0);
    let syncing = false;

    /** Uploads stay as Fusion has them (Vue would wrap them); enum cases are frozen, so Vue leaves them be. */
    const raw = (value) => {
        if (value instanceof Fusion.P.Upload) return markRaw(value);
        if (Array.isArray(value)) value.forEach(raw);
        else if (value !== null && typeof value === 'object' && Object.getPrototypeOf(value) === Object.prototype) Object.values(value).forEach(raw);
        return value;
    };

    for (const name of handle.properties) {
        values[name] = ref(raw(handle.get(name)));
        /** What the view changes (v-model, an assignment, deep inside too) goes to the component at once. */
        watch(values[name], (value) => syncing || handle.set(name, value), { deep: true, flush: 'sync' });
    }

    const computedValues = Object.fromEntries(handle.computed.map((name) => [name, computed(() => (tick.value, raw(handle.get(name))))]));
    const errors = computed(() => (tick.value, handle.errors()));

    const methods = {};
    for (const name of handle.methods.filter((method) => !handle.computed.includes(method))) {
        methods[name] = (...args) => handle.call(name, ...args);
    }
    for (const name of handle.server) {
        const status = reactive({ processing: false, failed: false, error: null });
        const call = (...args) => {
            Object.assign(status, { processing: true, failed: false, error: null });
            return handle.call(name, ...args).then(
                (value) => {
                    status.processing = false;
                    return value;
                },
                (error) => {
                    Object.assign(status, { processing: false, failed: true, error: String(error?.message ?? error) });
                    throw error;
                },
            );
        };
        for (const key of ['processing', 'failed', 'error']) Object.defineProperty(call, key, { get: () => status[key] });
        methods[name] = call;
    }

    const api = { ...values, ...computedValues, ...methods, errors, dispatch: (event, ...params) => handle.dispatch(event, ...params) };

    const mixin = {
        computed: {
            ...Object.fromEntries(Object.entries(values).map(([name, value]) => [name, { get: () => value.value, set: (next) => (value.value = next) }])),
            ...Object.fromEntries(Object.entries(computedValues).map(([name, value]) => [name, () => value.value])),
            errors: () => errors.value,
        },
        methods: { ...methods, dispatch: api.dispatch },
    };

    return {
        api,
        mixin,
        /** What changed in the component: its values again (not sent back), and the derived ones. */
        update(names) {
            syncing = true;
            try {
                for (const name of names.includes('*') ? Object.keys(values) : names) {
                    if (values[name]) values[name].value = raw(handle.get(name));
                }
            } finally {
                syncing = false;
            }
            tick.value++;
        },
    };
}
