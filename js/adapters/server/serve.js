/**
 * The render service: the app's views rendered on the server, for the page's first HTML. The
 * app's SSR entry names a renderer per engine and serves them; `vite build --ssr` bundles it, and
 * php artisan fusion:ssr runs the bundle.
 *
 *     // resources/js/ssr.js
 *     import { serve } from 'nitro-fusion/ssr';
 *     import { renderVue } from 'nitro-fusion/vue/server';
 *     import { renderReact } from 'nitro-fusion/react/server';
 *
 *     serve({
 *         vue: renderVue(import.meta.glob('../views/vue/**\/*.vue', { eager: true })),
 *         react: renderReact(import.meta.glob('../views/react/**\/*.jsx', { eager: true })),
 *     });
 *
 * Laravel (View\ServerRendering\NodeRenderer) posts a view's state to /render and gets
 * {"html": "..."}; a view that fails answers 500 with {"error": "..."}, and the browser renders
 * that view. An engine it has no renderer for answers 404 with {"unserved": true}: those views
 * are the browser's. GET /health answers while the service runs.
 */
import { createServer } from 'node:http';
import { handleFrom } from './handle.js';

/** An engine the service has no renderer for: its views are the browser's, which is no failure. */
export class Unserved extends Error {}

/** One view's HTML, from the state Laravel sent: the service's work, without the HTTP. */
export async function render(renderers, state) {
    const renderer = renderers[state.engine];
    if (!renderer) throw new Unserved(`no renderer for ${state.engine} views: serve({ ${state.engine}: ... }) names none`);
    return renderer.render(state.view, handleFrom(state));
}

/**
 * @param {object} renderers   engine => renderer ({ render(view, handle) }): renderVue(), ...
 * @param {object} options     port (FUSION_SSR_PORT, 13714) and host (127.0.0.1: Laravel only)
 */
export function serve(renderers, { port = Number(process.env.FUSION_SSR_PORT ?? 13714), host = '127.0.0.1' } = {}) {
    const answer = (response, status, body) => {
        response.writeHead(status, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify(body));
    };

    const server = createServer((request, response) => {
        if (request.method === 'GET' && request.url === '/health') return answer(response, 200, { engines: Object.keys(renderers) });
        if (request.method !== 'POST' || request.url !== '/render') return answer(response, 404, { error: 'POST /render, or GET /health' });

        const chunks = [];
        request.on('data', (chunk) => chunks.push(chunk));
        request.on('end', async () => {
            try {
                const state = JSON.parse(Buffer.concat(chunks).toString('utf8'));
                answer(response, 200, { html: await render(renderers, state) });
            } catch (error) {
                if (error instanceof Unserved) answer(response, 404, { unserved: true, error: error.message });
                else answer(response, 500, { error: String(error?.message ?? error) });
            }
        });
    });

    server.listen(port, host, () => console.log(`Fusion render service: http://${host}:${port} (${Object.keys(renderers).join(', ')})`));

    return server;
}
