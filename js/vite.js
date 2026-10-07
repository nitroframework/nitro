/**
 * Fusion's Vite plugin, for views written in Vue, React or Solid:
 *
 *     // vite.config.js
 *     import fusion from './vendor/nitro/fusion/js/vite.js';
 *
 *     export default defineConfig({ plugins: [laravel(...), fusion(), vue(), react(), ...] });
 *
 *  - 'nitro-fusion/vue', 'nitro-fusion/react' and 'nitro-fusion/solid' name Fusion's adapters;
 *    'nitro-fusion/ssr' and 'nitro-fusion/{vue,react,solid}/server' the render service's parts,
 *    for the app's SSR entry (vite build --ssr)
 *  - one Vue, React and Solid: the adapters' imports resolve to the app's own
 *  - a .vue file's <php> block (its component's PHP, which Fusion reads on the server) is left
 *    out of what Vue compiles; its lines stay, blank, so Vue's line numbers are the file's. In a
 *    .jsx or .tsx file the block is a comment, which needs nothing.
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
                    exactly('nitro-fusion/vue/server', 'server/vue'),
                    exactly('nitro-fusion/react/server', 'server/react'),
                    exactly('nitro-fusion/solid/server', 'server/solid'),
                    exactly('nitro-fusion/ssr', 'server/serve'),
                ],
                dedupe: ['vue', 'react', 'react-dom', 'solid-js'],
            },
        }),

        transform(code, id) {
            if (!/\.vue($|\?)/.test(id) || id.includes('?') || !code.includes('<php>')) return null;
            return { code: code.replace(/<php>[\s\S]*?<\/php>/, (block) => block.replace(/[^\n]/g, '')), map: null };
        },
    };
}
