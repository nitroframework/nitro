/**
 * fusion:loading: what shows while a component waits for the server.
 *
 *   fusion:loading                   shown only while loading
 *   fusion:loading.remove            hidden while loading
 *   fusion:loading.class="opacity-50" classes added while loading (.class.remove: removed)
 *   fusion:loading.attr="disabled"   attribute set while loading
 *   fusion:loading.delay...          any of them once the call has taken 200ms (.delay.500ms, .delay.1s)
 *   fusion:target="save,delete"      only for calls to these methods
 *
 * The plain forms are hidden by the stylesheet; a delayed one, whose name the stylesheet can't
 * know, by data-fusion-loading-hidden, which this sets.
 */
import { duration } from './events.js';

export const STYLE = '[fusion\\:loading]:not([data-fusion-loading]){display:none!important}'
    + '[fusion\\:loading\\.remove][data-fusion-loading]{display:none!important}'
    + '[data-fusion-loading-hidden]{display:none!important}';

/** .delay without a time, as Livewire's. */
const DELAY = 200;

/** Delayed attributes, by element: each one's timer, and whether its wait is over. */
const waits = new WeakMap();

export function applyLoading(component, active) {
    const methods = active;
    for (const el of component.scan('*')) {
        if (component.runtime.owner(el) !== component) continue;
        const attributes = el.getAttributeNames().filter((n) => n === 'fusion:loading' || n.startsWith('fusion:loading.'));
        if (attributes.length === 0) continue;

        const target = el.getAttribute('fusion:target');
        const on = methods.size > 0 && (!target || target.split(',').map((t) => t.trim()).some((t) => methods.has(t)));

        on ? el.setAttribute('data-fusion-loading', '') : el.removeAttribute('data-fusion-loading');

        for (const attribute of attributes) {
            const [wait, form] = parse(attribute);
            apply(el, attribute, form, wait === null ? on : delayed(el, attribute, form, on, wait));
        }
    }
}

/** fusion:loading.delay.500ms.class: [the wait in ms (null: none), the form: ['class']]. */
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
    } else if (attribute !== 'fusion:loading' && attribute !== 'fusion:loading.remove') {
        const hidden = form[0] === 'remove' ? on : !on;
        hidden ? el.setAttribute('data-fusion-loading-hidden', '') : el.removeAttribute('data-fusion-loading-hidden');
    }
}
