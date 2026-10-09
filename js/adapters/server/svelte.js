/**
 * Svelte views in the render service:
 *
 *     import { renderSvelte } from 'nitro/svelte/server';
 *
 *     serve({ svelte: renderSvelte(import.meta.glob('../views/svelte/**\/*.svelte', { eager: true })) });
 *
 * The props and context are the browser's (adapters/svelte.svelte.js, view()), so hydrate()
 * finds what was rendered. svelte/server's render() only: Svelte's public API.
 */
import { render } from 'svelte/server';
import { findView } from '../core.js';
import { view } from '../svelte.svelte.js';

export function renderSvelte(views) {
    return {
        async render(name, handle) {
            const View = await findView(views, name, 'svelte');
            const { props, context } = view(handle);
            return render(View, { props, context }).body;
        },
    };
}
