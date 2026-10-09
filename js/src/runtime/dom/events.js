/**
 * nitro:<event> handlers. The render marks each element with nitro:h-<event> and gives its
 * component the handler for it; one listener per event type on the document finds the marked
 * elements from the target up and runs their handlers, applying the modifiers.
 */
import { ATTRIBUTES, selectorOf } from '../protocol.js';
const KEYS = {
    enter: 'Enter', escape: 'Escape', esc: 'Escape', space: ' ', tab: 'Tab', delete: 'Delete',
    backspace: 'Backspace', up: 'ArrowUp', down: 'ArrowDown', left: 'ArrowLeft', right: 'ArrowRight',
};
const SYSTEM = { shift: 'shiftKey', ctrl: 'ctrlKey', alt: 'altKey', meta: 'metaKey' };
/** A key written as its letter or digit: .k, .1 (Globals::isModifier()). */
const LETTER_OR_DIGIT = /^[a-z0-9]$/;

/**
 * Whether the event is the modifier's key. A letter in either case (Shift+K is .k), or with Alt
 * held its key on the keyboard (Option+K types "˚" on a Mac); a digit, or its key on the keyboard
 * (Shift+2 types "@").
 */
function isKey(event, modifier) {
    if (KEYS[modifier]) return event.key === KEYS[modifier];
    if (/^\d$/.test(modifier)) return event.key === modifier || event.code === `Digit${modifier}`;
    return event.key.toLowerCase() === modifier || (event.altKey && event.code === `Key${modifier.toUpperCase()}`);
}
const NON_BUBBLING = new Set(['focus', 'blur', 'mouseenter', 'mouseleave', 'scroll']);
const GLOBAL = ['window', 'document', 'outside'];

export class Events {
    constructor(runtime, document) {
        this.runtime = runtime;
        this.document = document;
        this.listening = new Set();
        /** element => (handler key => timer): .debounce and .throttle, each handler its own */
        this.timers = new WeakMap();
        /** element => the keys of its .once handlers that ran */
        this.fired = new WeakMap();
        /** event => (element => answer): nitro:confirm asks once, however many of the element's handlers it runs */
        this.answers = new WeakMap();
    }

    /** Listen for the event types a component uses. */
    watch(component) {
        for (const event of component.events) {
            if (this.listening.has(event) || event === 'poll' || event === 'init' || event === 'visible') continue; // Hooks runs those
            this.listening.add(event);
            this.document.addEventListener(event, (e) => this.dispatch(event, e), { capture: NON_BUBBLING.has(event) });
            if (event === 'scroll' || event === 'resize') {
                this.document.defaultView?.addEventListener(event, (e) => this.dispatch(event, e));
            }
        }
    }

    dispatch(type, event) {
        const attribute = ATTRIBUTES.handler + type;

        /** Handlers on the target and its ancestors, innermost first. */
        for (let el = event.target instanceof Element ? event.target : event.target?.parentElement; el; el = el.parentElement) {
            if (!el.hasAttribute?.(attribute)) continue;
            let stop = false;
            for (const handler of this.handlersOf(el, type)) {
                if (handler.modifiers.some((m) => GLOBAL.includes(m))) continue;
                if (this.run(handler, el, event) === 'stop') stop = true;
            }
            if (stop) break;
        }

        /** .window / .document handlers run for any such event; .outside when it is not inside. */
        for (const component of this.runtime.components()) {
            for (const el of component.scan(selectorOf(ATTRIBUTES.handler + type))) {
                if (!el.hasAttribute(attribute)) continue;
                for (const handler of component.handlersOf(el, type)) {
                    if (!handler.modifiers.some((m) => GLOBAL.includes(m))) continue;
                    if (handler.modifiers.includes('outside') && el.contains(event.target)) continue;
                    this.run(handler, el, event);
                }
            }
        }
    }

    handlersOf(el, type) {
        return this.runtime.owner(el)?.handlersOf(el, type) ?? [];
    }

    run(handler, el, event) {
        const m = handler.modifiers;
        if (m.includes('self') && event.target !== el) return;
        if (typeof event.key === 'string') {
            const keys = m.filter((x) => KEYS[x] || LETTER_OR_DIGIT.test(x));
            if (keys.length && !keys.some((k) => isKey(event, k))) return;
        }
        /** .shift, .ctrl, .alt, .meta: held during the event, a key's or a click's. */
        for (const [name, flag] of Object.entries(SYSTEM)) if (m.includes(name) && !event[flag]) return;
        /** nitro:submit never lets the browser post the form: the handler is what submitting does. */
        if (m.includes('prevent') || event.type === 'submit') event.preventDefault();
        if (!this.answers.has(event)) this.answers.set(event, new Map());
        const answers = this.answers.get(event);
        if (!answers.has(el)) answers.set(el, this.confirmed(el));
        if (!answers.get(el)) return;
        if (m.includes('once')) {
            if (!this.fired.has(el)) this.fired.set(el, new Set());
            if (this.fired.get(el).has(handler.key)) return;
            this.fired.get(el).add(handler.key);
        }

        const invoke = () => {
            Promise.resolve()
                .then(() => handler.fn(event))
                .catch((error) => this.runtime.report(null, error));
        };

        const wait = timing(m);
        if (!this.timers.has(el)) this.timers.set(el, new Map());
        const timers = this.timers.get(el);
        if (m.includes('debounce')) {
            clearTimeout(timers.get(handler.key));
            timers.set(handler.key, setTimeout(invoke, wait ?? 250));
        } else if (m.includes('throttle')) {
            if (timers.has(handler.key)) return m.includes('stop') ? 'stop' : undefined;
            invoke();
            timers.set(handler.key, setTimeout(() => timers.delete(handler.key), wait ?? 250));
        } else {
            invoke();
        }

        if (m.includes('stop')) {
            event.stopPropagation();
            return 'stop';
        }
    }

    /**
     * nitro:confirm="Delete it?" on the element asks before its handlers run (the browser's own
     * dialog). nitro:confirm.prompt="Type DELETE to confirm|DELETE" asks for the word after the
     * last |. \n in the message is a line break.
     */
    confirmed(el) {
        const plain = el.getAttribute('nitro:confirm');
        const prompt = el.getAttribute('nitro:confirm.prompt');
        const window = this.document.defaultView;
        const text = (s) => s.replace(/\\n/g, '\n');

        if (prompt !== null) {
            const bar = prompt.lastIndexOf('|');
            const [message, expected] = bar === -1 ? [prompt, ''] : [prompt.slice(0, bar), prompt.slice(bar + 1)];
            const answer = window.prompt(text(message));
            return answer !== null && answer.trim() === expected.trim();
        }

        return plain === null || window.confirm(text(plain));
    }
}

/** A modifier's time in ms (300ms, 1s), or null when it is not one. Support\Duration checks the same form. */
export function duration(modifier) {
    const match = /^(\d+)(ms|s)$/.exec(modifier ?? '');
    return match ? Number(match[1]) * (match[2] === 's' ? 1000 : 1) : null;
}

/** The first time among the modifiers: .debounce.300ms, .throttle.1s */
export function timing(modifiers) {
    for (const m of modifiers) {
        const ms = duration(m);
        if (ms !== null) return ms;
    }
    return null;
}
