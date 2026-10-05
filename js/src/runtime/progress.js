/**
 * The progress bar (nitro-progress) while a fusion:navigate visit loads, and, when configured,
 * while #[Server] calls run. Settings come from config/nprogress.php through @fusionScripts.
 *
 * A bar runs for at least minimumDuration once it shows: a visit faster than that keeps it
 * moving until then, so it is seen to complete rather than flash. A visit faster than `delay`
 * still shows none.
 */
import { createProgress } from 'nitro-progress';

export class Progress {
    /** @param {object|null} options  the settings, null when the bar is off */
    constructor(window, options) {
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

        window.addEventListener('fusion:navigating', () => this.update(() => (this.navigating = true)));
        window.addEventListener('fusion:navigated', () => this.update(() => (this.navigating = false)));
        if (options.serverCalls) {
            window.addEventListener('fusion:request', () => this.update(() => this.calls++));
            window.addEventListener('fusion:response', () => this.update(() => (this.calls = Math.max(0, this.calls - 1))));
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
