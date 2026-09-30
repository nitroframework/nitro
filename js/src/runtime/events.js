/**
 * fusion:<event> handlers. The render marks each element with fusion:h-<event> and gives its
 * component the handler for it; one listener per event type on the document finds the marked
 * elements from the target up and runs their handlers, applying the modifiers.
 */
const KEYS = {
    enter: 'Enter', escape: 'Escape', esc: 'Escape', space: ' ', tab: 'Tab', delete: 'Delete',
    backspace: 'Backspace', up: 'ArrowUp', down: 'ArrowDown', left: 'ArrowLeft', right: 'ArrowRight',
};
const SYSTEM = { shift: 'shiftKey', ctrl: 'ctrlKey', alt: 'altKey', meta: 'metaKey' };
const NON_BUBBLING = new Set(['focus', 'blur', 'mouseenter', 'mouseleave', 'scroll']);
const GLOBAL = ['window', 'document', 'outside'];

export class Events {
    constructor(runtime, document) {
        this.runtime = runtime;
        this.document = document;
        this.listening = new Set();
        this.timers = new WeakMap();
        this.fired = new WeakSet();
    }

    /** Listen for the event types a component uses. */
    watch(component) {
        for (const event of component.events) {
            if (this.listening.has(event) || event === 'poll' || event === 'init') continue; // Hooks runs those
            this.listening.add(event);
            this.document.addEventListener(event, (e) => this.dispatch(event, e), { capture: NON_BUBBLING.has(event) });
            if (event === 'scroll' || event === 'resize') {
                this.document.defaultView?.addEventListener(event, (e) => this.dispatch(event, e));
            }
        }
    }

    dispatch(type, event) {
        const attribute = `fusion:h-${type}`;

        /** Handlers on the target and its ancestors, innermost first. */
        for (let el = event.target instanceof Element ? event.target : event.target?.parentElement; el; el = el.parentElement) {
            if (!el.hasAttribute?.(attribute)) continue;
            const handler = this.handlerOf(el, type);
            if (!handler || handler.modifiers.some((m) => GLOBAL.includes(m))) continue;
            if (this.run(handler, el, event) === 'stop') break;
        }

        /** .window / .document handlers run for any such event; .outside when it is not inside. */
        for (const component of this.runtime.components()) {
            for (const el of component.scan(`[fusion\\:h-${type}]`)) {
                const handler = el.hasAttribute(attribute) ? component.handler(el, type) : null;
                if (!handler || !handler.modifiers.some((m) => GLOBAL.includes(m))) continue;
                if (handler.modifiers.includes('outside') && el.contains(event.target)) continue;
                this.run(handler, el, event);
            }
        }
    }

    handlerOf(el, type) {
        return this.runtime.owner(el)?.handler(el, type) ?? null;
    }

    run(handler, el, event) {
        const m = handler.modifiers;
        if (m.includes('self') && event.target !== el) return;
        if (typeof event.key === 'string') {
            const keys = m.filter((x) => KEYS[x]);
            if (keys.length && !keys.some((k) => event.key === KEYS[k])) return;
            for (const [name, flag] of Object.entries(SYSTEM)) if (m.includes(name) && !event[flag]) return;
        }
        if (m.includes('prevent')) event.preventDefault();
        if (!this.confirmed(el)) return;
        if (m.includes('once')) {
            if (this.fired.has(el)) return;
            this.fired.add(el);
        }

        const invoke = () => {
            Promise.resolve()
                .then(() => handler.fn(event))
                .catch((error) => this.runtime.report(null, error));
        };

        const wait = timing(m);
        if (m.includes('debounce')) {
            clearTimeout(this.timers.get(el));
            this.timers.set(el, setTimeout(invoke, wait ?? 250));
        } else if (m.includes('throttle')) {
            if (this.timers.has(el)) return m.includes('stop') ? 'stop' : undefined;
            invoke();
            this.timers.set(el, setTimeout(() => this.timers.delete(el), wait ?? 250));
        } else {
            invoke();
        }

        if (m.includes('stop')) {
            event.stopPropagation();
            return 'stop';
        }
    }

    /**
     * fusion:confirm="Delete it?" on the element asks before its handlers run (the browser's own
     * dialog). fusion:confirm.prompt="Type DELETE to confirm|DELETE" asks for the word after the
     * last |. \n in the message is a line break.
     */
    confirmed(el) {
        const plain = el.getAttribute('fusion:confirm');
        const prompt = el.getAttribute('fusion:confirm.prompt');
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

/** .debounce.300ms, .throttle.1s */
export function timing(modifiers) {
    for (const m of modifiers) {
        const match = /^(\d+)(ms|s)$/.exec(m);
        if (match) return Number(match[1]) * (match[2] === 's' ? 1000 : 1);
    }
    return null;
}
