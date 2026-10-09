/**
 * nitro:transition, as Vue's <Transition>: an element animates as it enters (an @if that
 * becomes true, a new row of a @foreach) and as it leaves (it stays in the page until its leave
 * animation ends, then goes).
 *
 *   nitro:transition="fade"            Vue's classes: fade-enter-from, fade-enter-active,
 *                                       fade-enter-to, then fade-leave-from, -leave-active, -leave-to;
 *                                       the CSS says what moves, and for how long
 *   nitro:transition                   a built-in fade and scale, no CSS needed:
 *                                       .opacity or .scale (one only), .duration.300ms, .origin.top
 *   .appear                             on the component's first render too (a visit's new page);
 *                                       never on the server's HTML, which is there already
 *   .in / .out                          entering only / leaving only
 *   .out-in / .in-out                   Vue's modes, for an element replacing another (@if / @else):
 *                                       the old one leaves before the new enters, or after
 *   .move                               rows of a @foreach slide to their new places (Vue's
 *                                       <TransitionGroup>; with a name, its name-move class)
 *
 * Nothing animates in a hidden tab, or for a visitor who asks for reduced motion.
 */
import { duration } from '../dom/events.js';

/** The element's transition, from its nitro:transition attribute; null for none. */
export function transitionOf(el) {
    if (el?.nodeType !== 1) return null;
    const attribute = el.getAttributeNames().find((name) => name === 'nitro:transition' || name.startsWith('nitro:transition.'));
    if (!attribute) return null;

    const modifiers = attribute.split('.').slice(1);
    const after = (name) => {
        const at = modifiers.indexOf(name);
        return at === -1 ? null : modifiers[at + 1] ?? null;
    };

    return {
        name: (el.getAttribute(attribute) ?? '').trim(),
        appear: modifiers.includes('appear'),
        enter: !modifiers.includes('out'),
        leave: !modifiers.includes('in'),
        opacity: !modifiers.includes('scale') || modifiers.includes('opacity'),
        scale: !modifiers.includes('opacity') || modifiers.includes('scale'),
        duration: duration(after('duration')) ?? 150,
        origin: after('origin'),
        mode: modifiers.includes('out-in') ? 'out-in' : modifiers.includes('in-out') ? 'in-out' : null,
        move: modifiers.includes('move'),
    };
}

/** Elements entering and leaving, by their parent: a mode waits for the others. */
const running = new WeakMap();

function runningIn(parent) {
    if (!running.has(parent)) running.set(parent, { entering: new Set(), leaving: new Set() });
    return running.get(parent);
}

function track(set, promise) {
    set.add(promise);
    const done = () => set.delete(promise);
    promise.then(done, done);
    return promise;
}

/**
 * The element came in (render.js). With .out-in it shows once what it replaces has left: it waits
 * a microtask, as the branch going may update after the one coming.
 */
export function entering(el, spec) {
    const parent = el.parentNode;
    if (spec.mode !== 'out-in' || !parent) return track(runningIn(parent ?? el).entering, enter(el, spec));

    const display = el.style.display;
    el.style.display = 'none';
    const shown = new Promise((resolve) => queueMicrotask(resolve))
        .then(() => Promise.all(runningIn(parent).leaving))
        .then(() => {
            el.style.display = display;
            return enter(el, spec);
        });

    return track(runningIn(parent).entering, shown);
}

/**
 * The element is going (anchor.js): the promise settles when it may be removed. With .in-out it
 * waits for what replaces it to enter. With .move it leaves the flow at once, where it is, so the
 * rows after it slide up while it fades.
 */
export function leaving(el, spec) {
    const parent = el.parentNode;
    if (spec.move) lift(el);

    const gone = spec.mode === 'in-out' && parent
        ? new Promise((resolve) => queueMicrotask(resolve)).then(() => Promise.all(runningIn(parent).entering)).then(() => leave(el, spec))
        : leave(el, spec);

    return parent ? track(runningIn(parent).leaving, gone) : gone;
}

/** Out of the flow, kept where it shows: absolute, at its box. */
function lift(el) {
    const win = el.ownerDocument.defaultView;
    const box = el.getBoundingClientRect();
    const holder = el.offsetParent;
    const positioned = holder && win.getComputedStyle(holder).position !== 'static';
    const origin = positioned ? holder.getBoundingClientRect() : { top: -win.scrollY, left: -win.scrollX };

    Object.assign(el.style, {
        position: 'absolute',
        margin: '0',
        top: box.top - origin.top - (positioned ? holder.clientTop - holder.scrollTop : 0) + 'px',
        left: box.left - origin.left - (positioned ? holder.clientLeft - holder.scrollLeft : 0) + 'px',
        width: box.width + 'px',
        height: box.height + 'px',
    });
}

/** Where the elements are: element => its box (before a list changes, for move()). */
export function positions(elements) {
    return new Map(elements.map((el) => [el, el.getBoundingClientRect()]));
}

/**
 * .move: from where it was to where it is now (FLIP). It is put back where it was with a
 * transform, then the transform goes, animated: by the built-in transition, or by the name-move
 * class's CSS.
 */
export async function move(el, spec, from) {
    const to = el.getBoundingClientRect();
    const dx = from.left - to.left;
    const dy = from.top - to.top;
    if (!dx && !dy) return;

    const win = el.ownerDocument.defaultView;
    const style = el.style;
    const saved = { transition: style.transition, transform: style.transform };

    style.transition = 'none';
    style.transform = `translate(${dx}px, ${dy}px)`;
    void el.offsetHeight; // the old place, laid out before the transition starts

    if (spec.name) {
        el.classList.add(`${spec.name}-move`);
        style.transition = saved.transition;
        style.transform = saved.transform;
        await ended(el, win);
        el.classList.remove(`${spec.name}-move`);
        return;
    }

    style.transition = `transform ${spec.duration}ms ease`;
    style.transform = saved.transform;
    await wait(win, spec.duration);
    style.transition = saved.transition;
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
