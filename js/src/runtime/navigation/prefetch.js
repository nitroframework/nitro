/**
 * Pages asked for ahead of the click (nitro:navigate.prefetch): once the pointer has rested on
 * the link (or the keyboard focused it), or with .prefetch.visible once the link comes into view.
 * Kept for 30 seconds (.prefetch.60s: longer) in the navigator's page cache, which a #[Server]
 * call clears (pages.js). Only a page this one renders in place is asked for: its data, not HTML.
 */
import { selector } from '../protocol.js';
import { timing } from '../dom/events.js';

/** How long the pointer rests on a link before its page is prefetched: passing over a menu asks for nothing. */
const HOVER_DELAY = 75;
const PREFETCH_FOR = 30_000;

export class Prefetcher {
    constructor(navigator) {
        this.navigator = navigator;
        this.runtime = navigator.runtime;
        this.window = navigator.window;
        this.document = navigator.document;

        this.hoverTimer = null;
        this.document.addEventListener('mouseover', (e) => this.hover(e));
        this.document.addEventListener('mouseout', () => this.window.clearTimeout(this.hoverTimer));
        this.document.addEventListener('focusin', (e) => this.hover(e));

        /** .prefetch.visible links, watched as they come into view (watch()); none without IntersectionObserver. */
        this.watched = new WeakSet();
        this.observer = typeof this.window.IntersectionObserver === 'function'
            ? new this.window.IntersectionObserver((entries) => this.seen(entries), { rootMargin: '0px' })
            : null;
    }

    /** The pointer on a nitro:navigate.prefetch link (or the keyboard's focus): its page asked for after a moment. */
    hover(event) {
        this.window.clearTimeout(this.hoverTimer);
        const found = this.navigator.target(event);
        if (!found || !found.modifiers.includes('prefetch')) return;
        this.hoverTimer = this.window.setTimeout(() => this.prefetch(found.url, prefetchFor(found.modifiers)), event.type === 'focusin' ? 0 : HOVER_DELAY);
    }

    /** The .prefetch.visible links under root (a page, a component that rendered), watched: each page is asked for once its link is in view. */
    watch(root) {
        if (!this.observer || !root?.querySelectorAll) return;
        for (const link of root.querySelectorAll('a[href]')) {
            if (this.watched.has(link)) continue;
            const modifiers = this.navigator.modifiersOf(link);
            if (!modifiers?.includes('prefetch') || !modifiers.includes('visible')) continue;
            this.watched.add(link);
            this.observer.observe(link);
        }
    }

    seen(entries) {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            this.observer.unobserve(entry.target);
            if (!entry.target.isConnected) continue;
            const url = new URL(entry.target.href, this.window.location.href);
            if (url.origin === this.window.location.origin) this.prefetch(url, prefetchFor(this.navigator.modifiersOf(entry.target) ?? []));
        }
    }

    /**
     * The page's data asked for ahead of the click, kept for ms. Only for a page this one renders
     * in place (its data, not HTML), and not for one a visit renders at once anyway (markup, client).
     */
    prefetch(url, ms = PREFETCH_FOR) {
        if (!this.document.querySelector(selector('page')) || this.runtime.markupPage(url.pathname) !== null) return;
        const pages = this.navigator.pages;
        if (pages.prefetching(url.href)) return;
        this.runtime.preload(url.pathname);
        pages.prefetch(url.href, this.navigator.request(url, true), ms);
    }
}

/** How long a link's prefetched page is kept: .prefetch.60s, .prefetch.500ms; 30 seconds by default. */
function prefetchFor(modifiers) {
    return timing(modifiers) ?? PREFETCH_FOR;
}
