/**
 * The Fusion runtime (dist/fusion.js), loaded by @fusionScripts before the compiled components
 * (app.js), which call Fusion.define() for each component. The page's components start once the
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

/** @fusionScripts says where everything is (the server's config is the one place): without it, nothing would. */
if (!config.endpoint || !config.chunks) {
    throw new Error('Fusion: load fusion.js with @fusionScripts, which tells it its endpoint and where its chunks are');
}

/** The page's CSRF token: @csrf in a view, and every request the runtime sends. */
B.session.token = csrf;

/** What Laravel's helpers need (routes, translations, public config, URLs): @fusionScripts' globals. */
Object.assign(B.globals, globalsOf(document) ?? {});
readStates(document);

const Fusion = new Runtime(window, config);
Fusion.P = P;
Fusion.B = B;
Fusion.progress = new Progress(window, config.progress);
window.Fusion = Fusion;

/** Fusion's own plugins: #[Persist] and #[Sync] on stores. */
Fusion.use(persist);
Fusion.use(sync);

/**
 * Start once every deferred script (app.js defines the components) has run: that is when
 * DOMContentLoaded fires. A deferred script runs while readyState is already "interactive", so
 * readyState alone cannot tell; "load" covers a runtime added after DOMContentLoaded.
 */
let started = false;
const start = () => {
    if (!started) {
        started = true;
        /** Scripts that ran before the runtime register their Fusion.directive()s now, before any component starts. */
        document.dispatchEvent(new CustomEvent('fusion:starting', { detail: { Fusion } }));
        Fusion.start();
    }
};

if (document.readyState === 'complete') {
    start();
} else {
    document.addEventListener('DOMContentLoaded', start);
    window.addEventListener('load', start);
}
