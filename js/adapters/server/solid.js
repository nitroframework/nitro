/**
 * Solid views in the render service:
 *
 *     import { renderSolid } from 'nitro/solid/server';
 *
 *     serve({ solid: renderSolid(import.meta.glob('../views/solid/**\/*.jsx', { eager: true })) });
 *
 * Solid compiles a view for where it runs: the render service's build compiles them for the
 * server, the browser's for the DOM, both hydratable (vite-plugin-solid's `ssr: true` does
 * both). The root is the browser's (adapters/solid.js, view()), so hydrate() matches it.
 */
import { renderToString } from 'solid-js/web';
import { findView } from '../core.js';
import { view } from '../solid.js';

export function renderSolid(views) {
    return {
        async render(name, handle) {
            const View = await findView(views, name, 'solid');
            /** Keyed by the component's id, as the browser's hydrate() is: several views on a page stay apart. */
            return renderToString(view(View, handle).root, { renderId: handle.id });
        },
    };
}
