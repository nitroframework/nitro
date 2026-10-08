/**
 * Vue views in the render service:
 *
 *     import { renderVue } from 'nitro-fusion/vue/server';
 *
 *     serve({ vue: renderVue(import.meta.glob('../views/vue/**\/*.vue', { eager: true })) });
 *
 * The app is the browser's (adapters/vue.js, createView()), made with createSSRApp(), so the
 * browser hydrates exactly what was rendered. `setup(app)` is fusionVue()'s: the same plugins.
 */
import { renderToString } from 'vue/server-renderer';
import { findView } from '../core.js';
import { createView } from '../vue.js';

export function renderVue(views, { setup } = {}) {
    return {
        async render(view, handle) {
            const module = await findView(views, view, 'vue');
            const { app, scope } = createView(module, handle, { setup, ssr: true });

            try {
                return await renderToString(app);
            } finally {
                scope.stop();
            }
        },
    };
}
