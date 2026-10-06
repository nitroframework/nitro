/**
 * Fusion's Vite plugin, for views written in Vue, React or Solid:
 *
 *     // vite.config.js
 *     import fusion from './vendor/nitro/fusion/js/vite.js';
 *
 *     export default defineConfig({ plugins: [laravel(...), fusion(), vue(), react(), ...] });
 *
 *  - 'nitro-fusion/vue', 'nitro-fusion/react' and 'nitro-fusion/solid' name Fusion's adapters
 *  - one Vue, React and Solid: the adapters' imports resolve to the app's own
 *  - a .vue file's <php> block (its component's PHP, which Fusion reads on the server) is left
 *    out of what Vue compiles; its lines stay, blank, so Vue's line numbers are the file's. In a
 *    .jsx or .tsx file the block is a comment, which needs nothing.
 */
import { fileURLToPath } from 'node:url';

const adapter = (name) => fileURLToPath(new URL(`./adapters/${name}.js`, import.meta.url));

export default function fusion() {
    return {
        name: 'nitro-fusion',
        enforce: 'pre',

        config: () => ({
            resolve: {
                alias: {
                    'nitro-fusion/vue': adapter('vue'),
                    'nitro-fusion/react': adapter('react'),
                    'nitro-fusion/solid': adapter('solid'),
                },
                dedupe: ['vue', 'react', 'react-dom', 'solid-js'],
            },
        }),

        transform(code, id) {
            if (!/\.vue($|\?)/.test(id) || id.includes('?') || !code.includes('<php>')) return null;
            return { code: code.replace(/<php>[\s\S]*?<\/php>/, (block) => block.replace(/[^\n]/g, '')), map: null };
        },
    };
}
