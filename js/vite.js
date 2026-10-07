/**
 * Fusion's Vite plugin, for views written in Vue, React, Solid or Svelte:
 *
 *     // vite.config.js
 *     import fusion from './vendor/nitro/fusion/js/vite.js';
 *
 *     export default defineConfig({ plugins: [laravel(...), fusion(), vue(), react(), ...] });
 *
 *  - 'nitro-fusion/{vue,react,solid,svelte}' name Fusion's adapters; 'nitro-fusion/ssr' and
 *    'nitro-fusion/{vue,react,solid,svelte}/server' the render service's parts, for the app's SSR
 *    entry (vite build --ssr)
 *  - one Vue, React, Solid and Svelte: the adapters' imports resolve to the app's own
 *  - a .vue or .svelte file's <php> block (its component's PHP, which Fusion reads on the server)
 *    is left out of what the framework compiles; its lines stay, blank, so the compiler's line
 *    numbers are the file's. In a .jsx or .tsx file the block is a comment, which needs nothing.
 */
import { fileURLToPath } from 'node:url';

const file = (path) => fileURLToPath(new URL(`./adapters/${path}.js`, import.meta.url));

/** Each name exactly: 'nitro-fusion/vue' is not a prefix of 'nitro-fusion/vue/server'. */
const exactly = (name, path) => ({ find: new RegExp(`^${name.replace(/\//g, '\\/')}$`), replacement: file(path) });

export default function fusion() {
    return {
        name: 'nitro-fusion',
        enforce: 'pre',

        config: () => ({
            resolve: {
                alias: [
                    exactly('nitro-fusion/vue', 'vue'),
                    exactly('nitro-fusion/react', 'react'),
                    exactly('nitro-fusion/solid', 'solid'),
                    /** A .svelte.js module: it uses runes, so the app's Svelte plugin compiles it. */
                    exactly('nitro-fusion/svelte', 'svelte.svelte'),
                    exactly('nitro-fusion/vue/server', 'server/vue'),
                    exactly('nitro-fusion/react/server', 'server/react'),
                    exactly('nitro-fusion/solid/server', 'server/solid'),
                    exactly('nitro-fusion/svelte/server', 'server/svelte'),
                    exactly('nitro-fusion/ssr', 'server/serve'),
                ],
                dedupe: ['vue', 'react', 'react-dom', 'solid-js', 'svelte'],
            },
        }),

        transform(code, id) {
            if (!/\.(vue|svelte)($|\?)/.test(id) || id.includes('?') || !code.includes('<php>')) return null;
            return { code: code.replace(/<php>[\s\S]*?<\/php>/, (block) => block.replace(/[^\n]/g, '')), map: null };
        },
    };
}
