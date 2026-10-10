/**
 * A thin progress bar along the top (or bottom) of the window, for page loads and requests.
 *
 * It slides out to its minimum at once and creeps towards its maximum while waiting, quickly at
 * first, then slower. On done() it waits until the new content is painted (two frames), then
 * fills and fades straight away, so the bar ends as the page appears rather than trailing after
 * it. Work that ends within the delay shows nothing; a start while the last bar fades begins a
 * new bar from the start.
 *
 * The whole creep is one CSS transition of `transform`, which the browser runs off the main
 * thread: the bar keeps moving while the page renders the new content. (Creeping by a timer, as
 * NProgress does, stops whenever the page is busy, then jumps to the end.)
 *
 * The markup is NProgress's (#nprogress, .bar, .peg, .spinner), so styles written for it apply.
 */

export const defaults = {
    /** Any CSS colour. */
    color: '#29d',
    /** Thickness, a CSS length. */
    height: '3px',
    /** 'top' or 'bottom' of the window; anything else is the top. */
    position: 'top',
    /** A glow at the bar's leading end, and its width. */
    glow: true,
    glowWidth: '100px',
    /** A spinning circle in the corner, and its size. */
    spinner: false,
    spinnerSize: '18px',
    zIndex: 1031,
    /** Where the bar starts and how far it may creep, from 0 to 1. */
    minimum: 0.15,
    maximum: 0.92,
    /** Creep while waiting: 18% of the way left every trickleSpeed ms. */
    trickle: true,
    trickleSpeed: 100,
    /** Out to the minimum and the fill at the end (ms, CSS easing), and the fade (ms). */
    speed: 150,
    easing: 'ease-out',
    fadeSpeed: 200,
    /** Wait this long (ms) before showing: work quicker than that shows nothing. */
    delay: 0,
};

/** A value inside a CSS declaration: nothing that could end it. */
const css = (value) => String(value).replace(/[;{}<>\\]/g, '');

function styles(o) {
    const color = css(o.color);
    const edge = o.position === 'bottom' ? 'bottom' : 'top';

    // #nprogress is the fixed box that fades, the bar inside it: a bar fixed on its own, over a
    // layer of the page (a sticky sidebar), is painted apart from the fade and stays behind.
    return `
#nprogress { pointer-events: none; position: fixed; z-index: ${Number(o.zIndex)}; ${edge}: 0; left: 0; width: 100%; height: ${css(o.height)}; transition: opacity ${Number(o.fadeSpeed)}ms ease; }
#nprogress .bar { background: ${color}; position: absolute; ${edge}: 0; left: 0; width: 100%; height: 100%; transform: translate3d(-100%, 0, 0); will-change: transform; }
#nprogress .peg { display: ${o.glow ? 'block' : 'none'}; position: absolute; right: 0; width: ${css(o.glowWidth)}; height: 100%; box-shadow: 0 0 10px ${color}, 0 0 5px ${color}; opacity: 1; transform: rotate(3deg) translate(0px, ${edge === 'top' ? '-4px' : '4px'}); }
#nprogress .spinner { display: block; position: fixed; z-index: ${Number(o.zIndex)}; ${edge}: 15px; right: 15px; }
#nprogress .spinner-icon { width: ${css(o.spinnerSize)}; height: ${css(o.spinnerSize)}; box-sizing: border-box; border: solid 2px transparent; border-top-color: ${color}; border-left-color: ${color}; border-radius: 50%; animation: nitro-progress-spinner 400ms linear infinite; }
@keyframes nitro-progress-spinner { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { #nprogress, #nprogress .bar { transition: none !important; } }
`;
}

/**
 * The bar for a window: `start()` when work begins, `done()` when its result is on screen.
 *
 * @param {Window} window
 * @param {Partial<typeof defaults>} options
 */
export function createProgress(window = globalThis.window, options = {}) {
    const o = { ...defaults, ...options };
    const document = window.document;
    const clock = () => window.performance?.now() ?? Date.now();
    let el = null;
    let wait = null;
    let finishing = [];
    // Where the bar is going: creeping since `since`, or filling from `from` since `since`.
    let motion = null;

    const minimum = o.minimum * 100;
    const maximum = o.trickle ? Math.max(minimum, o.maximum * 100) : minimum;
    const easeOut = (x) => 1 - (1 - x) ** 2;

    /**
     * How far the bar has crept t ms after it showed: out to its minimum in `speed` ms, then 18%
     * of the way left to its maximum every trickleSpeed ms (NProgress's trickle, without a timer).
     */
    function crept(t) {
        if (t < o.speed) return minimum * easeOut(t / o.speed);
        if (!o.trickle) return minimum;
        return maximum - (maximum - minimum) * 0.82 ** ((t - o.speed) / o.trickleSpeed);
    }

    /** The creep as one transition: linear() through points on the curve. */
    const creepFor = o.speed + (o.trickle ? o.trickleSpeed * 30 : 0);
    const creepEasing = `linear(${Array.from({ length: 33 }, (_, i) => {
        if (i === 32) return '1 100%';
        const at = (i / 32) ** 1.8;
        return `${(crept(at * creepFor) / maximum).toFixed(4)} ${(at * 100).toFixed(2)}%`;
    }).join(', ')})`;

    const style = document.createElement('style');
    style.dataset.nitroProgress = '';
    style.textContent = styles(o);
    document.head.appendChild(style);

    /** Send the bar to `value` percent over ms; a browser without linear() eases out instead. */
    function move(value, ms, easing) {
        const bar = el?.querySelector('.bar');
        if (!bar) return;
        bar.style.transition = `transform ${ms}ms cubic-bezier(0.1, 0.7, 0.3, 1)`;
        bar.style.transition = `transform ${ms}ms ${easing}`;
        bar.style.transform = `translate3d(${value - 100}%, 0, 0)`;
    }

    /** How far along the bar is now, in percent. */
    function now() {
        if (!motion) return 0;
        const t = clock() - motion.since;
        if (motion.fill) return motion.from + (100 - motion.from) * easeOut(Math.min(1, t / o.speed));
        return crept(Math.min(t, creepFor));
    }

    function remove(target = el) {
        target?.remove();
        if (target === el) {
            el = null;
            motion = null;
        }
    }

    function later(fn, ms) {
        finishing.push(['timer', window.setTimeout(fn, ms)]);
    }

    /** The next frame; a timer too, as a tab in the background has no frames. */
    function frame(fn) {
        let ran = false;
        const run = () => {
            if (ran) return;
            ran = true;
            fn();
        };
        if (window.requestAnimationFrame) finishing.push(['frame', window.requestAnimationFrame(run)]);
        later(run, 50);
    }

    function cancelFinish() {
        for (const [kind, id] of finishing) {
            if (kind === 'frame') window.cancelAnimationFrame?.(id);
            else window.clearTimeout(id);
        }
        finishing = [];
    }

    /** A new bar, out to its minimum and creeping towards its maximum. */
    function show() {
        cancelFinish();
        remove();

        el = document.createElement('div');
        el.id = 'nprogress';
        el.setAttribute('role', 'progressbar');
        el.innerHTML = '<div class="bar"><div class="peg"></div></div>' + (o.spinner ? '<div class="spinner"><div class="spinner-icon"></div></div>' : '');
        document.body.appendChild(el);
        // Laid out at 0 first, so the creep runs from there.
        void window.getComputedStyle(el.querySelector('.bar')).transform;
        motion = { since: clock() };
        move(maximum, creepFor, o.trickle ? creepEasing : o.easing);
    }

    return {
        /** Work started: show the bar (after the delay). A bar still fading gives way to a new one. */
        start() {
            window.clearTimeout(wait);
            if (o.delay > 0) wait = window.setTimeout(show, o.delay);
            else show();
        },

        /** The result is on screen: fill and fade once it is painted. */
        done() {
            window.clearTimeout(wait);
            if (!el || motion?.fill) return;

            const current = el;
            frame(() =>
                frame(() => {
                    if (current !== el) return;
                    // From wherever the creep has got to: the browser carries on from there.
                    motion = { fill: true, from: now(), since: clock() };
                    move(100, o.speed, o.easing);
                    later(() => {
                        current.style.opacity = '0';
                        later(() => remove(current), o.fadeSpeed);
                    }, o.speed);
                }),
            );
        },

        /** Whether a bar is on screen (filling, creeping or fading). */
        get shown() {
            return el !== null;
        },

        /** How far along the bar is, in percent; 0 when none is shown. */
        get width() {
            return now();
        },

        /** Take the bar and its styles off the page. */
        destroy() {
            window.clearTimeout(wait);
            cancelFinish();
            remove();
            style.remove();
        },
    };
}
