// Builds dist/fusion.js (the runtime served by {endpoint}/fusion.js).
import * as esbuild from 'esbuild';

await esbuild.build({
    entryPoints: ['js/src/index.js'],
    bundle: true,
    format: 'iife',
    target: ['es2020'],
    minify: true,
    /** Bundled libraries' licence notices stay with them. */
    legalComments: 'eof',
    outfile: 'dist/fusion.js',
    logLevel: 'info',
});
