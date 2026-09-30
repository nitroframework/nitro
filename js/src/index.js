/**
 * The Fusion runtime (dist/fusion.js), loaded by @fusionScripts before the compiled components
 * (app.js), which call Fusion.define() for each component. The page's components start once the
 * document is parsed.
 */
import * as P from './php.js';
import * as B from './blade.js';
import { Progress } from './runtime/progress.js';
import { Runtime } from './runtime/runtime.js';

const script = document.currentScript;
const config = {
    endpoint: script?.dataset.endpoint ?? '/_fusion',
    csrf: script?.dataset.csrf ?? '',
    /** app.js's version: the #[LazyLoad] chunks load with it */
    bundle: script?.dataset.bundle ?? '',
    /** config/nprogress.php, absent when the bar is off */
    progress: script?.dataset.progress ? JSON.parse(script.dataset.progress) : null,
};

B.session.token = config.csrf;

/** What Laravel's helpers need (routes, translations, public config, URLs): @fusionScripts' globals. */
const globals = document.querySelector('script[data-fusion-globals]');
if (globals) Object.assign(B.globals, JSON.parse(globals.textContent));

const Fusion = new Runtime(window, config);
Fusion.P = P;
Fusion.B = B;
Fusion.progress = new Progress(window, config.progress);
window.Fusion = Fusion;

/**
 * Start once every deferred script (app.js defines the components) has run: that is when
 * DOMContentLoaded fires. A deferred script runs while readyState is already "interactive", so
 * readyState alone cannot tell; "load" covers a runtime added after DOMContentLoaded.
 */
let started = false;
const start = () => {
    if (!started) {
        started = true;
        Fusion.start();
    }
};

if (document.readyState === 'complete') {
    start();
} else {
    document.addEventListener('DOMContentLoaded', start);
    window.addEventListener('load', start);
}
