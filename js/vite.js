/**
 * Nitro's Vite plugin, for views written in Vue, React, Solid or Svelte:
 *
 *     // vite.config.js
 *     import nitro from './vendor/nitro/nitro/js/vite.js';
 *
 *     export default defineConfig({ plugins: [laravel(...), nitro(), vue(), react(), ...] });
 *
 *  - 'nitro/{vue,react,solid,svelte}' name Nitro's adapters, and 'nitro/lazy' loads one with the
 *    first view that needs it (lazy('vue', () => import('nitro/vue'), views)); 'nitro/ssr' and
 *    'nitro/{vue,react,solid,svelte}/server' the render service's parts, for the app's SSR
 *    entry (vite build --ssr)
 *  - one Vue, React, Solid and Svelte: the adapters' imports resolve to the app's own
 *  - a .vue or .svelte file's <php> block (its component's PHP, which Nitro reads on the server)
 *    is left out of what the framework compiles; its lines stay, blank, so the compiler's line
 *    numbers are the file's. In a .jsx or .tsx file the block is a comment, which needs nothing.
 *
 * only(): with React and Solid in one app, both compile .jsx, so each plugin is told which
 * files are its own:
 *
 *     react({ include: only('react') }),
 *     solid({ include: only('solid', 'resources/frontend/solid') }),
 */
import { fileURLToPath } from 'node:url';

const file = (path) => fileURLToPath(new URL(`./adapters/${path}.js`, import.meta.url));

/** Where React's and Solid's views are, as config/nitro.php's views gives them (OneHomeTest). */
export const folders = {
    react: ['resources/views/react', 'resources/js/react'],
    solid: ['resources/views/solid', 'resources/js/solid'],
};

/**
 * A plugin's include: the script files (.js, .jsx, .ts, .tsx) under the framework's view folders,
 * or under the folders given (from the app's root, with / or \). Matches Vite's ids, absolute
 * paths with either separator.
 */
export function only(framework, ...given) {
    if (!folders[framework] && given.length === 0) {
        throw new Error(`Nitro: only('${framework}') knows React's and Solid's folders; name this one's: only('${framework}', 'resources/views/${framework}')`);
    }

    const paths = (given.length ? given : folders[framework]).map((folder) => folder
        .split(/[\\/]+/)
        .filter((part) => part !== '' && part !== '.')
        .map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
        .join('[\\\\/]'));

    return new RegExp(`(^|[\\\\/])(${paths.join('|')})[\\\\/].*\\.[jt]sx?$`);
}

/** Each name exactly: 'nitro/vue' is not a prefix of 'nitro/vue/server'. */
const exactly = (name, path) => ({ find: new RegExp(`^${name.replace(/\//g, '\\/')}$`), replacement: file(path) });

export default function nitro() {
    return {
        name: 'nitro',
        enforce: 'pre',

        config: () => ({
            resolve: {
                alias: [
                    exactly('nitro/vue', 'vue'),
                    exactly('nitro/react', 'react'),
                    exactly('nitro/solid', 'solid'),
                    /** A .svelte.js module: it uses runes, so the app's Svelte plugin compiles it. */
                    exactly('nitro/svelte', 'svelte.svelte'),
                    exactly('nitro/lazy', 'lazy'),
                    exactly('nitro/vue/server', 'server/vue'),
                    exactly('nitro/react/server', 'server/react'),
                    exactly('nitro/solid/server', 'server/solid'),
                    exactly('nitro/svelte/server', 'server/svelte'),
                    exactly('nitro/ssr', 'server/serve'),
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
