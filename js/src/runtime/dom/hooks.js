/**
 * Handlers no DOM event starts (Globals::HOOKS), set up after each render:
 *
 *  - fusion:poll="method" calls it every 2.5s (.5s, .750ms: another interval; a bare fusion:poll
 *    refreshes). It waits while the tab is hidden (.keep-alive: it does not), polls only while
 *    the element is on screen with .visible, and skips a tick while the component's previous
 *    #[Server] call is still on its way. It stops when its element leaves the page.
 *  - fusion:init="method" calls it once when its element appears.
 *  - fusion:visible="method" calls it when its element comes into view (.once: the first time
 *    only; .200px: that far before it does). While the element stays in view, it calls it again
 *    once the call is done, as long as the call changed something: a "load more" at the end of a
 *    list keeps loading until the list fills the screen or has no more.
 */
import { timing } from './events.js';

const POLL = 2500;

export class Hooks {
    constructor(runtime, window) {
        this.runtime = runtime;
        this.window = window;
        this.polls = new Map(); // element => timer
        this.initialized = new WeakSet();
        /** fusion:visible: element => {component, once, inView, running}; an observer per margin. */
        this.watched = new Map();
        this.observers = new Map();
        /** fusion:visible.once elements already called. */
        this.done = new WeakSet();
    }

    /** After a render: start the component's new polls and inits. */
    sync(component) {
        for (const el of this.owned(component, 'fusion:h-init')) {
            if (this.initialized.has(el)) continue;
            this.initialized.add(el);
            this.call(component, el, 'fusion:h-init');
        }

        for (const el of this.owned(component, 'fusion:h-visible')) {
            if (!this.watched.has(el) && !this.done.has(el)) this.watch(component, el);
        }
        for (const [el, watch] of this.watched) {
            if (!el.isConnected || watch.component.destroyed) this.unwatch(el);
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

    /** fusion:visible: observe the element (without IntersectionObserver: call it once, now). */
    watch(component, el) {
        const modifiers = this.handler(component, el, 'fusion:h-visible')?.modifiers ?? [];
        const once = modifiers.includes('once');
        const margin = modifiers.find((m) => /^\d+px$/.test(m)) ?? '0px';

        if (typeof this.window.IntersectionObserver !== 'function') {
            this.done.add(el);
            this.call(component, el, 'fusion:h-visible');
            return;
        }

        let observer = this.observers.get(margin);
        if (!observer) {
            observer = new this.window.IntersectionObserver((entries) => {
                for (const entry of entries) this.seen(entry.target, entry.isIntersecting);
            }, { rootMargin: margin });
            this.observers.set(margin, observer);
        }
        this.watched.set(el, { component, once, inView: false, running: false, observer });
        observer.observe(el);
    }

    unwatch(el) {
        this.watched.get(el)?.observer?.unobserve(el);
        this.watched.delete(el);
    }

    seen(el, inView) {
        const watch = this.watched.get(el);
        if (!watch) return;
        const entered = inView && !watch.inView;
        watch.inView = inView;
        if (entered && !watch.running) this.visible(el, watch);
    }

    /** Call it; then again while it is still in view and the call changed something. */
    async visible(el, watch) {
        const { component } = watch;
        if (!el.isConnected || component.destroyed) return this.unwatch(el);
        if (watch.once) {
            this.unwatch(el);
            this.done.add(el);
        }
        watch.running = true;
        const renders = component.renders;
        try {
            await this.handler(component, el, 'fusion:h-visible')?.fn(null);
            /** A #[Server] method: its answer, then the render it brings. */
            await this.runtime.network.queues.get(component)?.catch(() => {});
            await new Promise((resolve) => this.window.setTimeout(resolve));
        } catch (error) {
            this.runtime.report(component, error);
        } finally {
            watch.running = false;
        }
        if (!watch.once && watch.inView && component.renders !== renders && this.watched.get(el) === watch) {
            this.visible(el, watch);
        }
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
