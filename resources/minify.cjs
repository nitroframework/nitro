// nitro:build: minify the compiled components with the application's own esbuild, as `vite build`
// does. Run from the application root (nitro.esbuild.root), so `esbuild` resolves from its
// node_modules.
//
//   node minify.cjs            the bundle on stdin, the minified bundle on stdout
//   node minify.cjs --batch    {name: code, ...} as JSON on stdin, {name: minified, ...} on stdout:
//                              every chunk in one process (starting Node is most of the cost of
//                              minifying one), minified in parallel
let esbuild;

try {
    esbuild = require(require.resolve('esbuild', { paths: [process.cwd()] }));
} catch {
    process.stderr.write(`esbuild is not installed in ${process.cwd()}: run npm install --save-dev esbuild`);
    process.exit(2);
}

const options = { minify: true, target: 'es2020', legalComments: 'none' };
const batch = process.argv.includes('--batch');

let source = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => (source += chunk));
process.stdin.on('end', async () => {
    if (!batch) {
        try {
            process.stdout.write(esbuild.transformSync(source, options).code);
        } catch (error) {
            process.stderr.write(String(error?.message ?? error));
            process.exit(1);
        }
        return;
    }

    const chunks = JSON.parse(source);
    const minified = {};
    try {
        await Promise.all(Object.entries(chunks).map(async ([name, code]) => {
            try {
                minified[name] = (await esbuild.transform(code, options)).code;
            } catch (error) {
                throw new Error(`${name === '' ? 'app' : name}: ${error?.message ?? error}`);
            }
        }));
    } catch (error) {
        process.stderr.write(String(error?.message ?? error));
        process.exit(1);
    }
    process.stdout.write(JSON.stringify(minified));
});
