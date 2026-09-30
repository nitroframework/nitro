// fusion:build: minify the compiled components with the application's own esbuild, as `vite build`
// does. Reads the bundle on stdin, writes the minified bundle on stdout. Run from the application
// root (fusion.esbuild.root), so `esbuild` resolves from its node_modules.
let esbuild;

try {
    esbuild = require(require.resolve('esbuild', { paths: [process.cwd()] }));
} catch {
    process.stderr.write(`esbuild is not installed in ${process.cwd()}: run npm install --save-dev esbuild`);
    process.exit(2);
}

let source = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => (source += chunk));
process.stdin.on('end', () => {
    try {
        const { code } = esbuild.transformSync(source, { minify: true, target: 'es2020', legalComments: 'none' });
        process.stdout.write(code);
    } catch (error) {
        process.stderr.write(String(error?.message ?? error));
        process.exit(1);
    }
});
