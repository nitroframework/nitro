// lazy() (nitro/lazy) with a stand-in adapter (stdin: anything): the engine registered at once, the
// adapter imported with the first view that loads and only then, kept, a failed import tried
// again, and a module that is no adapter refused. stdout: what went wrong.
import { readStdin } from './common.mjs';

await readStdin();

const registered = new Map();
globalThis.window = { Nitro: { engine: (name, engine) => registered.set(name, engine) } };
const { lazy } = await import('../../adapters/lazy.js');

const failures = [];
const check = (label, ok) => ok || failures.push(label);

/** A stand-in adapter: its engine loads a view by its name and mounts it, and it counts its imports. */
let imports = 0;
let failNext = false;
const adapter = () => {
    imports++;
    if (failNext) {
        failNext = false;
        return Promise.reject(new Error('network'));
    }
    return Promise.resolve({
        engine: (views, options) => ({
            load: (view) => Promise.resolve({ view, from: Object.keys(views), options }),
            mount: (module, el, handle, mountOptions) => ({ mounted: module.view, el, handle, hydrate: mountOptions?.hydrate }),
        }),
    });
};

lazy('vue', adapter, { './a.vue': {} }, { setup: 'plugins' });
const engine = registered.get('vue');
check('the engine is registered at once', engine !== undefined && typeof engine.load === 'function' && typeof engine.mount === 'function');
check('nothing is imported until a view loads', imports === 0);

const module = await engine.load('a');
check('the first view loads through the adapter, with the views and the options', imports === 1 && module.view === 'a' && module.from[0] === './a.vue' && module.options.setup === 'plugins');
await engine.load('b');
check('the adapter is imported once and kept', imports === 1);
const mounted = engine.mount(module, 'el', 'handle', { hydrate: true });
check("mount is the adapter's, with what Nitro passes", mounted.mounted === 'a' && mounted.el === 'el' && mounted.handle === 'handle' && mounted.hydrate === true);

imports = 0;
failNext = true;
lazy('react', adapter, {});
const flaky = registered.get('react');
const failed = await flaky.load('x').then(() => false, (error) => error.message === 'network');
check('a failed import fails the load', failed);
const again = await flaky.load('x').then((loaded) => loaded.view === 'x', () => false);
check('and is tried again with the next view', again && imports === 2);

lazy('solid', () => Promise.resolve({ nitroSolid() {} }), {});
const refused = await registered.get('solid').load('x').then(() => '', (error) => error.message);
check('a module that is no adapter is refused, saying what to import', refused.includes("() => import('nitro/solid')"));

process.stdout.write(JSON.stringify(failures));
