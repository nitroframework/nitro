/**
 * React views in the render service:
 *
 *     import { renderReact } from 'nitro/react/server';
 *
 *     serve({ react: renderReact(import.meta.glob('../views/react/**\/*.jsx', { eager: true })) });
 *
 * The element is the browser's (adapters/react.js, tree()), so hydrateRoot() finds what was
 * rendered. `wrap(element)` is nitroReact()'s: the same providers.
 */
import { renderToString } from 'react-dom/server';
import { findView } from '../core.js';
import { tree } from '../react.js';

export function renderReact(views, { wrap } = {}) {
    return {
        async render(view, handle) {
            const View = await findView(views, view, 'react');
            return renderToString(tree(View, handle, wrap).element);
        },
    };
}
