/**
 * nitro:loading: what shows while a component waits for the server.
 *
 *   nitro:loading                   shown only while loading
 *   nitro:loading.remove            hidden while loading
 *   nitro:loading.class="opacity-50" classes added while loading (.class.remove: removed)
 *   nitro:loading.attr="disabled"   attribute set while loading
 *   nitro:loading.delay...          any of them once the call has taken 200ms (.delay.500ms, .delay.1s)
 *   nitro:target="save,delete"      only for calls to these methods (or checks of these properties)
 *
 * A check (#[Validate(onBlur: true)]) shows only where nitro:target names its property: the
 * untargeted forms are for calls.
 *
 * The plain forms are hidden by the stylesheet; a delayed one, whose name the stylesheet can't
 * know, by data-nitro-loading-hidden, which this sets.
 */
import { duration } from './events.js';

export const STYLE = '[nitro\\:loading]:not([data-nitro-loading]){display:none!important}'
    + '[nitro\\:loading\\.remove][data-nitro-loading]{display:none!important}'
    + '[data-nitro-loading-hidden]{display:none!important}';

/** .delay without a time, as Livewire's. */
const DELAY = 200;

/** Delayed attributes, by element: each one's timer, and whether its wait is over. */
const waits = new WeakMap();

/** No checks running. */
const NONE = new Set();

/** active: the calls running (by method, or a property for an upload); checks: the properties being checked. */
export function applyLoading(component, active, checks = NONE) {
    const methods = active;
    for (const el of component.scan('*')) {
        if (component.runtime.owner(el) !== component) continue;
        const attributes = el.getAttributeNames().filter((n) => n === 'nitro:loading' || n.startsWith('nitro:loading.'));
        if (attributes.length === 0) continue;

        const target = el.getAttribute('nitro:target');
        const on = target
            ? target.split(',').map((t) => t.trim()).some((t) => methods.has(t) || checks.has(t))
            : methods.size > 0;

        on ? el.setAttribute('data-nitro-loading', '') : el.removeAttribute('data-nitro-loading');

        for (const attribute of attributes) {
            const [wait, form] = parse(attribute);
            apply(el, attribute, form, wait === null ? on : delayed(el, attribute, form, on, wait));
        }
    }
}

/** nitro:loading.delay.500ms.class: [the wait in ms (null: none), the form: ['class']]. */
function parse(attribute) {
    const modifiers = attribute.split('.').slice(1);
    if (modifiers[0] !== 'delay') return [null, modifiers];
    const wait = duration(modifiers[1]);
    return wait !== null ? [wait, modifiers.slice(2)] : [DELAY, modifiers.slice(1)];
}

/** Whether a delayed attribute is on: once the call has lasted its wait; a call ending sooner shows nothing. */
function delayed(el, attribute, form, on, wait) {
    if (!waits.has(el)) waits.set(el, new Map());
    const state = waits.get(el).get(attribute) ?? { timer: null, over: false };
    waits.get(el).set(attribute, state);

    if (!on) {
        clearTimeout(state.timer);
        state.timer = null;
        state.over = false;
        return false;
    }
    if (!state.over && state.timer === null) {
        state.timer = setTimeout(() => {
            state.timer = null;
            state.over = true;
            apply(el, attribute, form, true);
        }, wait);
    }
    return state.over;
}

function apply(el, attribute, form, on) {
    const value = el.getAttribute(attribute) ?? '';
    if (form[0] === 'class') {
        const classes = value.split(/\s+/).filter(Boolean);
        const add = form[1] === 'remove' ? !on : on;
        classes.forEach((c) => el.classList.toggle(c, add));
    } else if (form[0] === 'attr') {
        on ? el.setAttribute(value, '') : el.removeAttribute(value);
    } else if (attribute !== 'nitro:loading' && attribute !== 'nitro:loading.remove') {
        const hidden = form[0] === 'remove' ? on : !on;
        hidden ? el.setAttribute('data-nitro-loading-hidden', '') : el.removeAttribute('data-nitro-loading-hidden');
    }
}
