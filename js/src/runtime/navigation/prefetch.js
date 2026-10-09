/**
 * Pages asked for ahead of the click, for nitro:navigate links that have nitro:prefetch: once the
 * pointer has rested on the link (or the keyboard focused it), or with nitro:prefetch.visible once
 * the link comes into view. Kept for 30 seconds (.keep.60s: longer) in the navigator's page cache,
 * which a #[Server] call clears (pages.js). Only a page this one renders in place is asked for:
 * its data, not HTML.
 */
import { selector } from '../protocol.js';
import { duration } from '../dom/events.js';

/** How long the pointer rests on a link before its page is asked for (nitro:prefetch.150ms: that long): passing over a menu asks for nothing. */
const WAIT = 75;
const KEEP = 30_000;

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

        /** nitro:prefetch.visible links, watched as they come into view (watch()); none without IntersectionObserver. */
        this.watched = new WeakSet();
        this.observer = typeof this.window.IntersectionObserver === 'function'
            ? new this.window.IntersectionObserver((entries) => this.seen(entries), { rootMargin: '0px' })
            : null;
    }

    /** The pointer on a link with nitro:prefetch (or the keyboard's focus): its page asked for after its wait. */
    hover(event) {
        this.window.clearTimeout(this.hoverTimer);
        const found = this.navigator.target(event);
        const prefetch = found ? prefetchOf(found.link) : null;
        if (!prefetch || prefetch.visible) return;
        this.hoverTimer = this.window.setTimeout(() => this.prefetch(found.url, prefetch.keep), event.type === 'focusin' ? 0 : prefetch.wait);
    }

    /** The nitro:prefetch.visible links under root (a page, a component that rendered), watched: each page is asked for once its link is in view. */
    watch(root) {
        if (!this.observer || !root?.querySelectorAll) return;
        for (const link of root.querySelectorAll('a[href]')) {
            if (this.watched.has(link) || !this.navigator.modifiersOf(link) || !prefetchOf(link)?.visible) continue;
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
            if (url.origin === this.window.location.origin) this.prefetch(url, prefetchOf(entry.target)?.keep ?? KEEP);
        }
    }

    /**
     * The page's data asked for ahead of the click, kept for ms. Only for a page this one renders
     * in place (its data, not HTML), and not for one a visit renders at once anyway (markup, client).
     */
    prefetch(url, ms = KEEP) {
        if (!this.document.querySelector(selector('page')) || this.runtime.markupPage(url.pathname) !== null) return;
        const pages = this.navigator.pages;
        if (pages.prefetching(url.href)) return;
        this.runtime.preload(url.pathname);
        pages.prefetch(url.href, this.navigator.request(url, true), ms);
    }
}

/**
 * A link's nitro:prefetch: in view or on hover, the hover wait (a time first, or after .wait) and
 * how long the page is kept (a time after .keep); null without one. The compiler checked it.
 */
function prefetchOf(link) {
    const attribute = [...link.attributes].find((a) => a.name === 'nitro:prefetch' || a.name.startsWith('nitro:prefetch.'));
    if (!attribute) return null;
    const parts = attribute.name.split('.').slice(1);
    const after = (word) => (parts.includes(word) ? duration(parts[parts.indexOf(word) + 1]) : null);
    return {
        visible: parts.includes('visible'),
        wait: duration(parts[0]) ?? after('wait') ?? WAIT,
        keep: after('keep') ?? KEEP,
    };
}
