/**
 * A framework loaded with the first view that needs it, rather than with every page:
 *
 *     import { lazy } from 'nitro/lazy';
 *
 *     lazy('vue', () => import('nitro/vue'), import.meta.glob('../views/vue/**\/*.vue'));
 *     lazy('react', () => import('nitro/react'), import.meta.glob('../views/react/**\/*.jsx'), { wrap });
 *
 * The engine is registered at once, so components name it as before. Its adapter, and the
 * framework with it, is imported the first time one of its views loads: a page that shows one,
 * or a visit to one, which waits for its views before it shows the page. It is loaded once and
 * kept; a load that failed (a network blip) is tried again with the next view. `options` are the
 * adapter's own: { setup } for Vue, { wrap } for React.
 */
import { register } from './core.js';

/**
 * @param {string} name        the engine's name, as #[View(vue: ...)] names it
 * @param {Function} adapter   () => import('nitro/vue'): the adapter's module
 * @param {object} views       what import.meta.glob() gives
 * @param {object} [options]   the adapter's options
 */
export function lazy(name, adapter, views, options) {
    let loaded = null;
    let loading = null;

    const ready = () =>
        (loading ??= Promise.resolve()
            .then(adapter)
            .then(
                (module) => {
                    if (typeof module?.engine !== 'function') throw new Error(`Nitro: lazy('${name}', ...) loads an adapter: () => import('nitro/${name}')`);
                    return (loaded = module.engine(views, options));
                },
                (error) => {
                    loading = null;
                    throw error;
                },
            ));

    register(name, {
        load: (view) => ready().then((engine) => engine.load(view)),
        /** Nitro mounts a view with the module load() gave, so the adapter is here by then. */
        mount: (view, el, handle, options) => loaded.mount(view, el, handle, options),
    });
}
