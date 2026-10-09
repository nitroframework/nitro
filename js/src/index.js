/**
 * The Nitro runtime (dist/nitro.js), loaded by @nitroScripts before the compiled components
 * (app.js), which call Nitro.define() for each component. The page's components start once the
 * document is parsed.
 */
import * as P from './php.js';
import * as B from './laravel/blade.js';
import { Progress } from './runtime/navigation/progress.js';
import { Runtime } from './runtime/runtime.js';
import { configOf, globalsOf, readStates } from './runtime/page-data.js';
import { persist } from './plugins/persist.js';
import { sync } from './plugins/sync.js';

const { csrf, ...config } = document.currentScript ? configOf(document.currentScript) : {};

/** @nitroScripts says where everything is (the server's config is the one place): without it, nothing would. */
if (!config.endpoint || !config.chunks) {
    throw new Error('Nitro: load nitro.js with @nitroScripts, which tells it its endpoint and where its chunks are');
}

/** The page's CSRF token: @csrf in a view, and every request the runtime sends. */
B.session.token = csrf;

/** What Laravel's helpers need (routes, translations, public config, URLs): @nitroScripts' globals. */
Object.assign(B.globals, globalsOf(document) ?? {});
readStates(document);

const Nitro = new Runtime(window, config);
Nitro.P = P;
Nitro.B = B;
Nitro.progress = new Progress(window, config.progress);
window.Nitro = Nitro;

/** Nitro's own plugins: #[Persist] and #[Sync] on stores. */
Nitro.use(persist);
Nitro.use(sync);

/**
 * Start once every deferred script (app.js defines the components) has run: that is when
 * DOMContentLoaded fires. A deferred script runs while readyState is already "interactive", so
 * readyState alone cannot tell; "load" covers a runtime added after DOMContentLoaded.
 */
let started = false;
const start = () => {
    if (!started) {
        started = true;
        /** Scripts that ran before the runtime register their Nitro.directive()s now, before any component starts. */
        document.dispatchEvent(new CustomEvent('nitro:starting', { detail: { Nitro } }));
        Nitro.start();
    }
};

if (document.readyState === 'complete') {
    start();
} else {
    document.addEventListener('DOMContentLoaded', start);
    window.addEventListener('load', start);
}
