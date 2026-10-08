// Builds dist/fusion.js (the runtime served by {endpoint}/fusion.js) and dist/fusion-devtools.js
// (the dev overlay, served by {endpoint}/fusion-devtools.js in development only).
import * as esbuild from 'esbuild';

const build = (entry, outfile) => esbuild.build({
    entryPoints: [entry],
    bundle: true,
    format: 'iife',
    target: ['es2020'],
    minify: true,
    /** Bundled libraries' licence notices stay with them. */
    legalComments: 'eof',
    outfile,
    logLevel: 'info',
});

await build('js/src/index.js', 'dist/fusion.js');
await build('js/src/devtools/index.js', 'dist/fusion-devtools.js');
