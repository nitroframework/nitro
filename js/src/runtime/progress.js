/**
 * The progress bar (nitro-progress) while a fusion:navigate visit loads, and, when configured,
 * while #[Server] calls run. Settings come from config/nprogress.php through @fusionScripts.
 */
import { createProgress } from 'nitro-progress';

export class Progress {
    /** @param {object|null} options  the settings, null when the bar is off */
    constructor(window, options) {
        /** A visit replaces the one before it (one navigated for several navigating): a flag, not a count. */
        this.navigating = false;
        this.calls = 0;
        this.bar = null;
        if (!options) return;

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
        if (!was && this.busy) this.bar.start();
        else if (was && !this.busy) this.bar.done();
    }
}
