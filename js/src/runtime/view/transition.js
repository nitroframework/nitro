/**
 * fusion:transition, as Vue's <Transition>: an element animates as it enters (an @if that
 * becomes true, a new row of a @foreach) and as it leaves (it stays in the page until its leave
 * animation ends, then goes).
 *
 *   fusion:transition="fade"            Vue's classes: fade-enter-from, fade-enter-active,
 *                                       fade-enter-to, then fade-leave-from, -leave-active, -leave-to;
 *                                       the CSS says what moves, and for how long
 *   fusion:transition                   a built-in fade and scale, no CSS needed:
 *                                       .opacity or .scale (one only), .duration.300ms, .origin.top
 *   .appear                             on the component's first render too (a visit's new page);
 *                                       never on the server's HTML, which is there already
 *   .in / .out                          entering only / leaving only
 *
 * Nothing animates in a hidden tab, or for a visitor who asks for reduced motion.
 */

/** The element's transition, from its fusion:transition attribute; null for none. */
export function transitionOf(el) {
    if (el?.nodeType !== 1) return null;
    const attribute = el.getAttributeNames().find((name) => name === 'fusion:transition' || name.startsWith('fusion:transition.'));
    if (!attribute) return null;

    const modifiers = attribute.split('.').slice(1);
    const after = (name) => {
        const at = modifiers.indexOf(name);
        return at === -1 ? null : modifiers[at + 1] ?? null;
    };
    const duration = after('duration');

    return {
        name: (el.getAttribute(attribute) ?? '').trim(),
        appear: modifiers.includes('appear'),
        enter: !modifiers.includes('out'),
        leave: !modifiers.includes('in'),
        opacity: !modifiers.includes('scale') || modifiers.includes('opacity'),
        scale: !modifiers.includes('opacity') || modifiers.includes('scale'),
        duration: duration === null ? 150 : duration.endsWith('ms') ? parseInt(duration, 10) : parseFloat(duration) * 1000,
        origin: after('origin'),
    };
}

/** Nothing to animate: the visitor asked for reduced motion, or the page is in a hidden tab. */
export function motionless(el) {
    return el.ownerDocument.hidden || (el.ownerDocument.defaultView?.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false);
}

/** The element came in: animate it from hidden. */
export function enter(el, spec) {
    return spec.name ? classes(el, spec.name, 'enter') : builtIn(el, spec, 'enter');
}

/** The element is going: animate it out; the promise settles when it may be removed. */
export function leave(el, spec) {
    return spec.name ? classes(el, spec.name, 'leave') : builtIn(el, spec, 'leave');
}

/** Vue's way: -from and -active, then on the next frame -to; both end with the CSS animation. */
async function classes(el, name, phase) {
    const win = el.ownerDocument.defaultView;
    const from = `${name}-${phase}-from`;
    const active = `${name}-${phase}-active`;
    const to = `${name}-${phase}-to`;

    el.classList.add(from, active);
    await nextFrame(win);
    el.classList.remove(from);
    el.classList.add(to);
    await ended(el, win);
    el.classList.remove(active, to);
}

/** The built-in fade and scale, with inline styles (put back as they were after). */
async function builtIn(el, spec, phase) {
    const win = el.ownerDocument.defaultView;
    const style = el.style;
    const saved = { transition: style.transition, opacity: style.opacity, transform: style.transform, transformOrigin: style.transformOrigin };
    const hide = () => {
        if (spec.opacity) style.opacity = '0';
        if (spec.scale) style.transform = 'scale(0.95)';
    };
    const show = () => {
        style.opacity = saved.opacity;
        style.transform = saved.transform;
    };

    if (spec.origin) style.transformOrigin = spec.origin;
    if (phase === 'enter') {
        hide();
        void el.offsetHeight; // the hidden state, laid out before the transition starts
    }
    style.transition = [spec.opacity && `opacity ${spec.duration}ms ease`, spec.scale && `transform ${spec.duration}ms ease`].filter(Boolean).join(', ');
    await nextFrame(win);
    phase === 'enter' ? show() : hide();
    await wait(win, spec.duration);

    style.transition = saved.transition;
    style.transformOrigin = saved.transformOrigin;
}

/** When the element's CSS transition or animation is over (at once when it has none). */
function ended(el, win) {
    const css = win.getComputedStyle?.(el);
    const longest = (durations, delays) => Math.max(0, ...String(durations ?? '').split(',').map((d, i) => seconds(d) + seconds(String(delays ?? '').split(',')[i] ?? '0')));
    const time = Math.max(longest(css?.transitionDuration, css?.transitionDelay), longest(css?.animationDuration, css?.animationDelay)) * 1000;
    if (!time) return Promise.resolve();

    return new Promise((resolve) => {
        const done = (event) => {
            if (event && event.target !== el) return;
            el.removeEventListener('transitionend', done);
            el.removeEventListener('animationend', done);
            win.clearTimeout(timer);
            resolve();
        };
        el.addEventListener('transitionend', done);
        el.addEventListener('animationend', done);
        const timer = win.setTimeout(done, time + 50);
    });
}

function seconds(value) {
    const time = parseFloat(value);
    if (Number.isNaN(time)) return 0;
    return String(value).trim().endsWith('ms') ? time / 1000 : time;
}

/** The next frame; a timer too, as a tab hidden meanwhile has no frames. */
function nextFrame(win) {
    return new Promise((resolve) => {
        win.requestAnimationFrame?.(() => resolve());
        win.setTimeout(resolve, 50);
    });
}

function wait(win, ms) {
    return new Promise((resolve) => win.setTimeout(resolve, ms));
}
