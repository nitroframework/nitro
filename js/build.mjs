// Builds dist/nitro.js (the runtime served by {endpoint}/nitro.js) and dist/nitro-devtools.js
// (the dev overlay, served by {endpoint}/nitro-devtools.js in development only).
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

await build('js/src/index.js', 'dist/nitro.js');
await build('js/src/devtools/index.js', 'dist/nitro-devtools.js');
