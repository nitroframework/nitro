/**
 * The progress bar (NProgress) while a fusion:navigate visit loads, and, when configured, while
 * #[Server] calls run. Settings come from config/nprogress.php through @fusionScripts. The styles
 * are NProgress's own, sized and coloured from those settings.
 */
import NProgress from 'nprogress';

/** A config value inside a CSS declaration: nothing that could end it. */
const css = (value) => String(value).replace(/[;{}<>\\]/g, '');

function styles(o) {
    const color = css(o.color);
    const edge = o.position === 'bottom' ? 'bottom' : 'top';
    /**
     * NProgress fades #nprogress. As NProgress styles it, #nprogress is an empty box and the bar
     * is fixed, outside it: over a layer of the page's (a sticky or scrolling sidebar) the browser
     * painted that part of the bar apart from the fade, and it stayed while the rest faded, as if
     * the bar went back from right to left. So #nprogress is the fixed box, the bar in it.
     */
    return `
#nprogress { pointer-events: none; position: fixed; z-index: ${Number(o.zIndex)}; ${edge}: 0; left: 0; width: 100%; height: ${css(o.height)}; }
#nprogress .bar { background: ${color}; position: absolute; ${edge}: 0; left: 0; width: 100%; height: 100%; }
#nprogress .peg { display: ${o.glow ? 'block' : 'none'}; position: absolute; right: 0; width: ${css(o.glowWidth)}; height: 100%; box-shadow: 0 0 10px ${color}, 0 0 5px ${color}; opacity: 1; transform: rotate(3deg) translate(0px, ${edge === 'top' ? '-4px' : '4px'}); }
#nprogress .spinner { display: block; position: fixed; z-index: ${Number(o.zIndex)}; ${edge}: 15px; right: 15px; }
#nprogress .spinner-icon { width: ${css(o.spinnerSize)}; height: ${css(o.spinnerSize)}; box-sizing: border-box; border: solid 2px transparent; border-top-color: ${color}; border-left-color: ${color}; border-radius: 50%; animation: nprogress-spinner 400ms linear infinite; }
@keyframes nprogress-spinner { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
`;
}

export class Progress {
    /** @param {object|null} options  the settings, null when the bar is off */
    constructor(window, options) {
        this.window = window;
        this.options = options;
        /** A visit replaces the one before it (one navigated for several navigating): a flag, not a count. */
        this.navigating = false;
        this.calls = 0;
        this.timer = null;
        if (!options) return;

        NProgress.configure({
            minimum: options.minimum,
            easing: options.easing,
            speed: options.speed,
            trickle: options.trickle,
            trickleSpeed: options.trickleSpeed,
            showSpinner: options.spinner,
        });

        const style = window.document.createElement('style');
        style.textContent = styles(options);
        window.document.head.appendChild(style);

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

    /**
     * Apply a change: when loading starts, show the bar once it has taken longer than the delay;
     * when nothing is loading any more, complete it (or never show it).
     */
    update(change) {
        const was = this.busy;
        change();
        if (!was && this.busy) {
            this.window.clearTimeout(this.timer);
            if (this.options.delay > 0) {
                this.timer = this.window.setTimeout(() => this.start(), this.options.delay);
            } else {
                this.start();
            }
        } else if (was && !this.busy) {
            this.window.clearTimeout(this.timer);
            if (NProgress.isStarted()) NProgress.done();
        }
    }

    /**
     * NProgress runs one bar at a time. done() queues the bar's finish (to 100%, fade, removal):
     * started before that has run, NProgress takes the fading bar back to the start and the
     * queued removal then takes the new bar away. So a start waits for the last bar to go.
     */
    start() {
        if (!this.busy) return;
        if (NProgress.isRendered() && !NProgress.isStarted()) {
            this.timer = this.window.setTimeout(() => this.start(), 50);
            return;
        }
        NProgress.start();
    }
}
