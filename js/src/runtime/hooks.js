/**
 * Handlers no DOM event starts (Globals::HOOKS), set up after each render:
 *
 *  - fusion:poll="method" calls it every 2.5s (.5s, .750ms: another interval; a bare fusion:poll
 *    refreshes). It waits while the tab is hidden (.keep-alive: it does not), polls only while
 *    the element is on screen with .visible, and skips a tick while the component's previous
 *    #[Server] call is still on its way. It stops when its element leaves the page.
 *  - fusion:init="method" calls it once when its element appears.
 */
import { timing } from './events.js';

const POLL = 2500;

export class Hooks {
    constructor(runtime, window) {
        this.runtime = runtime;
        this.window = window;
        this.polls = new Map(); // element => timer
        this.initialized = new WeakSet();
    }

    /** After a render: start the component's new polls and inits. */
    sync(component) {
        for (const el of this.owned(component, 'fusion:h-init')) {
            if (this.initialized.has(el)) continue;
            this.initialized.add(el);
            this.call(component, el, 'fusion:h-init');
        }

        for (const el of this.owned(component, 'fusion:h-poll')) {
            if (this.polls.has(el)) continue;
            const handler = this.handler(component, el, 'fusion:h-poll');
            const every = timing(handler?.modifiers ?? []) ?? POLL;
            this.polls.set(el, this.window.setInterval(() => this.tick(component, el), every));
        }
    }

    tick(component, el) {
        /** Its element left the page, or its component did: stop. */
        if (!el.isConnected || component.destroyed || !el.hasAttribute('fusion:h-poll')) {
            this.window.clearInterval(this.polls.get(el));
            this.polls.delete(el);
            return;
        }

        const modifiers = this.handler(component, el, 'fusion:h-poll')?.modifiers ?? [];
        if (this.window.document.hidden && !modifiers.includes('keep-alive')) return;
        if (modifiers.includes('visible') && !this.onScreen(el)) return;
        if (this.runtime.inflight.get(component)?.size) return;

        this.call(component, el, 'fusion:h-poll');
    }

    call(component, el, attribute) {
        const handler = this.handler(component, el, attribute);
        if (!handler) return;
        Promise.resolve()
            .then(() => handler.fn(null))
            .catch((error) => this.runtime.report(component, error));
    }

    /** The handler the component's render gave the element. */
    handler(component, el, attribute) {
        return component.handler(el, attribute.slice('fusion:h-'.length));
    }

    /** The component's elements with the attribute, not its children's. */
    owned(component, attribute) {
        const selector = `[${attribute.replace(':', '\\:')}]`;
        const all = component.scan(selector);
        return all.filter((el) => this.runtime.owner(el) === component);
    }

    onScreen(el) {
        const box = el.getBoundingClientRect();
        return box.bottom > 0 && box.right > 0 && box.top < this.window.innerHeight && box.left < this.window.innerWidth;
    }
}
