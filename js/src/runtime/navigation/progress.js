/**
 * The progress bar (bar.js) while a nitro:navigate visit loads, and, when configured,
 * while #[Server] calls run. Settings come from config/nprogress.php through @nitroScripts.
 *
 * A bar runs for at least minimumDuration once it shows: a visit faster than that keeps it
 * moving until then, so it is seen to complete rather than flash. A visit faster than `delay`
 * still shows none.
 */
import { createProgress } from './bar.js';
import { HOOK, REQUEST_KINDS } from '../hooks.js';

export class Progress {
    /** @param {object|null} options  the settings, null when the bar is off */
    constructor(runtime, options) {
        const window = runtime.window;
        /** A visit replaces the one before it (one navigated for several navigating): a flag, not a count. */
        this.navigating = false;
        this.calls = 0;
        this.bar = null;
        this.window = window;
        /** When the bar started, and the timer finishing it once it has run long enough. */
        this.started = 0;
        this.finishing = null;
        if (!options) return;

        this.delay = Math.max(0, Number(options.delay ?? 0));
        this.minimum = Math.max(0, Number(options.minimumDuration ?? 0));
        this.bar = createProgress(window, options);

        /** A visit cancelled after the bar heard it start: as it was before (another visit may be under way). */
        let before = false;
        runtime.hook(HOOK.beforeNavigate, () => this.update(() => {
            before = this.navigating;
            this.navigating = true;
        }));
        runtime.hook(HOOK.afterNavigate, ({ cancelled }) => this.update(() => (this.navigating = cancelled ? before : false)));
        if (options.serverCalls) {
            runtime.hook(HOOK.beforeRequest, ({ kind }) => kind === REQUEST_KINDS.call && this.update(() => this.calls++));
            runtime.hook(HOOK.afterRequest, ({ kind }) => kind === REQUEST_KINDS.call && this.update(() => (this.calls = Math.max(0, this.calls - 1))));
        }
    }

    get busy() {
        return this.navigating || this.calls > 0;
    }

    /** Apply a change: loading started, start the bar; nothing loading any more, finish it. */
    update(change) {
        const was = this.busy;
        change();
        if (!was && this.busy) this.start();
        else if (was && !this.busy) this.finish();
    }

    start() {
        /** Loading again while the bar waits to finish: the same bar goes on. */
        if (this.finishing !== null) {
            this.window.clearTimeout(this.finishing);
            this.finishing = null;
            return;
        }
        this.started = this.window.performance.now();
        this.bar.start();
    }

    finish() {
        const elapsed = this.window.performance.now() - this.started;
        /** Not shown yet (faster than the delay): it never will be. Shown: it runs its minimum first. */
        const wait = elapsed < this.delay ? 0 : this.delay + this.minimum - elapsed;
        if (wait <= 0) {
            this.bar.done();
            return;
        }
        this.finishing = this.window.setTimeout(() => {
            this.finishing = null;
            this.bar.done();
        }, wait);
    }
}
