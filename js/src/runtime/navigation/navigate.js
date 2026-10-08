/**
 * fusion:navigate: links that change the page without reloading it, rendered in the browser.
 * The visit asks the URL itself for the page's data (X-Fusion-Navigate), so Laravel still routes
 * it, with its middleware, bindings and redirects; a Route::fusion() page answers with its
 * component's signed state and title, and the browser renders the compiled component in place of
 * the current page (the element marked fusion:page). The rest of the layout, and its state, stays.
 * A #[LazyLoad] page's chunk loads during the visit, alongside its data (Runtime::preload()).
 *
 * A markup-first page (#[Navigate('markup')], fusion.navigation) in the same layout renders at
 * once, before its data: its properties' defaults, each counting as deferred, so @placeholder
 * shows. The data, asked for with the id the browser gave the page (X-Fusion-Id), then fills it in
 * (Runtime::arrive()), keeping what changed meanwhile. Any other answer goes on as a data-first visit.
 *
 * Any other page of the application (a Blade view, a controller's view, a page in another layout,
 * or a visit from such a page) comes as HTML and takes the current document's place (Swap), as
 * wire:navigate does: still without reloading. An error, another build of Fusion or a page
 * without @fusionScripts loads normally.
 *
 * fusion:navigate.keypress starts the visit as the mouse button goes down, not when it comes back
 * up (the click): the page is on its way during the press. The click that follows is the same
 * visit. Touch keeps the tap (a finger going down may be a scroll), and so does the keyboard.
 *
 * fusion:navigate.prefetch asks for the page's data ahead of the click (prefetch.js: when the pointer
 * rests on the link, or with .prefetch.visible when it comes into view). Back and forward show the
 * pages visited in the last five minutes without asking again. Both are in memory only, and any
 * #[Server] call forgets them (pages.js).
 *
 * With fusion.view_transitions on, or on a fusion:navigate.transition link, the page changes inside
 * the browser's View Transitions (a cross-fade by default, ::view-transition-* in CSS for more);
 * a browser without them changes it as usual.
 *
 * fusion:current="active" on a link adds the class while the link points at the current page
 * (fusion:current.exact: the same path only).
 *
 * The scroll position is kept in each history entry: back and forward restore it, and
 * fusion:navigate.preserve-scroll keeps the current one (filters, tabs). Each visit sends the
 * bundle's version (X-Fusion-Version): after a deploy the server answers 409 and the page loads in
 * full, with the new components.
 *
 * #[Remember] properties are kept in the history entry (saveRemembered()): back, forward and a
 * reload bring back the values they had there, in place of those the page starts with.
 *
 * #[Url] properties are kept in the query string (syncUrl()); the server reads them back when it
 * renders the page, so a reload, a shared link and back/forward show the same state.
 */
import * as P from '../../php.js';
import { ATTRIBUTES, selector } from '../protocol.js';
import { PageCache } from './pages.js';
import { Prefetcher } from './prefetch.js';
import { Swap } from './swap.js';

import { readState } from '../component/snapshot.js';
import { remembered as rememberedOf, restoreRemembered } from '../component/remember.js';
import { visit } from '../server/request.js';

/**
 * A URL the browser may be sent to: http(s) only. A redirect can come from what a visitor typed
 * (a "return to" URL); javascript: or data: there would run script on the page.
 */
export function webUrl(href, base) {
    let url;
    try {
        url = new URL(href, base);
    } catch {
        return null;
    }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        console.error(`Fusion: not sending the browser to ${url.protocol} URLs`);
        return null;
    }
    return url;
}

export class Navigator {
    constructor(runtime, window) {
        this.runtime = runtime;
        this.window = window;
        this.document = window.document;
        this.swap = new Swap(runtime, window);
        this.visiting = null;
        /** The link a press already started a visit for, until its click has come and gone. */
        this.pressed = null;

        this.document.addEventListener('pointerdown', (e) => this.press(e));
        this.document.addEventListener('pointerup', () => this.window.setTimeout(() => (this.pressed = null)));
        this.document.addEventListener('click', (e) => this.click(e));

        /** Prefetched and visited pages; a #[Server] call may have changed what they show. */
        this.pages = new PageCache();
        /** Pages asked for ahead of the click: into this.pages (prefetch.js). */
        this.prefetcher = new Prefetcher(this);
        window.addEventListener('fusion:request', () => this.pages.clear());
        window.addEventListener('popstate', (e) => {
            /** The entry left is no longer the current one: a #[Remember] save waiting to retry would land on this one. */
            window.clearTimeout(this.rememberTimer);
            this.visit(window.location.href, false, { scroll: e.state?.fusionScroll ?? null });
        });

        /** Fusion restores the scroll position of visited pages itself; the browser keeps the current one. */
        if ('scrollRestoration' in window.history) window.history.scrollRestoration = 'manual';
        this.scrollTimer = null;
        window.addEventListener('scroll', () => {
            window.clearTimeout(this.scrollTimer);
            this.scrollTimer = window.setTimeout(() => this.rememberScroll(), 150);
        }, { passive: true });
    }

    /** A fusion:navigate link's modifiers; null for another link. */
    modifiersOf(link) {
        return navigateModifiers(link);
    }

    /**
     * The fusion:navigate link an event is on, where it goes and its modifiers (keypress,
     * preserve-scroll); null when the browser should handle it.
     */
    target(event) {
        /** A focus event has no button: it counts as the main one. */
        if (event.defaultPrevented || (event.button ?? 0) !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return null;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        const modifiers = link ? navigateModifiers(link) : null;
        if (!modifiers) return null;
        if (link.target && link.target !== '_self') return null;
        const url = new URL(link.href, this.window.location.href);
        return url.origin === this.window.location.origin ? { link, url, modifiers } : null;
    }

    press(event) {
        this.pressed = null;
        if (event.pointerType === 'touch') return;
        const found = this.target(event);
        if (!found || !found.modifiers.includes('keypress')) return;
        this.pressed = found.link;
        this.visit(found.url.href, true, { preserveScroll: found.modifiers.includes('preserve-scroll'), transition: found.modifiers.includes('transition') });
    }

    click(event) {
        const found = this.target(event);
        if (!found) return;
        event.preventDefault();
        if (this.pressed === found.link) {
            this.pressed = null;
            return;
        }
        this.visit(found.url.href, true, { preserveScroll: found.modifiers.includes('preserve-scroll'), transition: found.modifiers.includes('transition') });
    }

    /**
     * #[Remember]: the remembered properties saved to the current history entry as they change
     * (once per render), so that leaving it at once (back, forward) keeps the latest values.
     */
    remember() {
        this.saveRemembered();
    }

    saveRemembered() {
        this.window.clearTimeout(this.rememberTimer);
        const remembered = rememberedOf(this.runtime);
        const state = { ...(this.window.history.state ?? {}) };
        if (remembered === null && !state.fusionRemember) return;
        remembered === null ? delete state.fusionRemember : (state.fusionRemember = remembered);
        try {
            this.window.history.replaceState(state, '', this.window.location.href);
        } catch {
            /** Too many history updates (Safari's limit): again in a moment. */
            this.rememberTimer = this.window.setTimeout(() => this.saveRemembered(), 1000);
        }
    }

    /** The current scroll position, kept in the current history entry. */
    rememberScroll() {
        const state = { ...(this.window.history.state ?? {}), fusionScroll: { x: this.window.scrollX, y: this.window.scrollY } };
        try {
            this.window.history.replaceState(state, '', this.window.location.href);
        } catch {
            // too many history updates (Safari's limit): the next scroll keeps it
        }
    }

    /**
     * Visit a URL: push a history entry (or not, for back and forward), then restore a remembered
     * scroll position (options.scroll), keep the current one (options.preserveScroll), or go to the
     * #hash or the top.
     */
    async visit(href, push = true, options = {}) {
        const url = webUrl(href, this.window.location.href);
        if (!url) return;
        if (url.origin !== this.window.location.origin) {
            this.window.location.href = url.href;
            return;
        }
        /** The page Fusion renders into; none on a page that is not Fusion's (its HTML comes then). */
        let current = this.document.querySelector(selector('page'));

        const visit = (this.visiting = {});
        this.runtime.emit('fusion:navigating', { url: url.href });
        /** A #[LazyLoad] page's chunk loads while its data does. */
        this.runtime.preload(url.pathname);

        /** A markup-first page in this layout: rendered at once, its data filling it in when it comes. */
        const early = current ? await this.renderEarly(current, url, push, options, visit) : null;
        if (visit !== this.visiting) return;
        /** A client page: complete as it rendered, nothing to ask the server. */
        if (early?.definition.client) return;
        if (early) current = early.el;
        /** The history entry is pushed once: by the early render, or when the page arrives. */
        const go = (target) => {
            if (early) this.window.history.replaceState(this.window.history.state ?? {}, '', target.href);
            else if (push) this.leave(target);
        };

        /**
         * The page's data (a Fusion page answers with it, any other with its HTML); or its HTML.
         * Prefetched for a link, or, going back or forward, the page as it was visited: not asked
         * again. A page rendered early asks with its id, so it is not taken from there.
         */
        const kept = current !== null && !early ? await this.pages.take(url.href, !push) : null;
        let answer = kept ?? await this.request(url, current !== null, early?.id);
        /**
         * Kept for back and forward as the server sent it (fetched, or the prefetch taken), even
         * when a newer visit has started: it is that URL's page.
         */
        if (!early) this.pages.visited(answer.url.href, answer);
        if (visit !== this.visiting) return; // a newer visit started

        /** A Fusion page in another layout: its HTML. */
        if (answer.page?.fusion === 1 && answer.page.layout !== current?.getAttribute(ATTRIBUTES.page)) {
            answer = await this.request(answer.url, false);
            if (visit !== this.visiting) return;
        }

        /** Where the visit ended up (after redirects), with the link's #hash. */
        const target = new URL(answer.url.href);
        target.hash = url.hash;

        if (answer.html !== null) {
            const html = this.swap.read(answer.html, target);
            if (!html) {
                this.window.location.href = target.href;
                return;
            }
            /** Its components start as on a page load, #[Remember] values of a back or forward entry too. */
            await this.transition(options, () => this.swap.replace(html, target, () => go(target)));
            this.arrive(target, options);
            return;
        }

        const page = answer.page;
        if (page?.fusion !== 1) {
            this.window.location.href = target.href;
            return;
        }

        /** The chunks its components are in (the preload's, or any the path did not tell). */
        const waiting = this.runtime.require(this.runtime.pageComponents(page));
        if (waiting) {
            try {
                await waiting;
            } catch {
                this.window.location.href = target.href;
                return;
            }
            if (visit !== this.visiting) return;
        }

        /** Its JavaScript views' modules: the page shows with them rendered, not filling in after it. */
        const views = this.runtime.preloadViews(this.runtime.pageComponents(page));
        if (views) {
            await views;
            if (visit !== this.visiting) return;
        }

        go(target);
        /** Fusion::share()'s data for this page, before it renders (components outside it follow). */
        this.runtime.receive(page, null, true);

        /** The page rendered early: its data fills it in. */
        if (early && !early.destroyed && readState(page.snapshot).name === early.name && page.layout === early.el.getAttribute(ATTRIBUTES.page)) {
            this.runtime.pageMount.arrive(early, page);
            if (page.title !== null) this.document.title = page.title;
            this.updateHead(page.head);
            return;
        }

        if (!(await this.transition(options, () => this.runtime.pageMount.mountPage(current, page)))) {
            this.window.location.reload();
            return;
        }
        if (page.title !== null) this.document.title = page.title;
        this.updateHead(page.head);
        /** Back or forward: what #[Remember] properties had in that entry. */
        if (!push) restoreRemembered(this.runtime, this.window.history.state?.fusionRemember);
        this.arrive(target, options);
    }

    /**
     * A visit's change of page (apply), inside the browser's View Transitions when they are on
     * (fusion.view_transitions, or the link's .transition) and the browser has them; what apply
     * returns. The old page is captured before apply runs, the new one after. Not for a hidden
     * page (nothing to see; the browser would abort it), and an aborted one only skips its animation.
     */
    async transition(options, apply) {
        const on = options.transition || this.runtime.config.transitions;
        if (!on || typeof this.document.startViewTransition !== 'function' || this.document.visibilityState === 'hidden') return apply();
        let result;
        const transition = this.document.startViewTransition(async () => {
            result = await apply();
        });
        transition.ready.catch(() => {});
        transition.finished.catch(() => {});
        await transition.updateCallbackDone;
        return result;
    }

    /**
     * A markup-first page (#[Navigate('markup')], fusion.navigation) in the current layout,
     * rendered at once in place of the current page, before its data: the history entry pushed,
     * the scroll set, and on back or forward its #[Remember] values. Null for any other page, or
     * one that cannot render without its data; the visit then waits for the data as usual.
     */
    async renderEarly(current, url, push, options, visit) {
        /** The page, and for a client page the properties its route's parameters fill from the URL. */
        const match = this.runtime.markupMatch(url.pathname);
        if (match === null) return null;
        const { name, props } = match;
        const waiting = this.runtime.require([name]);
        if (waiting) {
            try {
                await waiting;
            } catch {
                return null;
            }
        }
        /** Its JavaScript views' modules, so it renders complete. */
        await this.runtime.preloadViews([name]);
        /** A newer visit started while the chunk loaded, or the page is no longer there: nothing to render into. */
        if (visit !== this.visiting || !current.isConnected) return null;
        const definition = this.runtime.definitions.get(name);
        if (!definition?.markup || definition.markup !== current.getAttribute(ATTRIBUTES.page)) return null;

        /** The page left keeps its #[Remember] values and scroll, as leave() does; the entry is pushed once rendered. */
        if (push) {
            this.saveRemembered();
            this.rememberScroll();
        }
        const early = await this.transition(options, () => this.runtime.pageMount.renderEarly(current, name, definition, props));
        if (!early) return null;
        if (push) this.window.history.pushState({}, '', url.href);
        else restoreRemembered(this.runtime, this.window.history.state?.fusionRemember);
        /** A client page's title and head, as the server would have sent them (worked out at build time). */
        if (definition.client) {
            if (definition.client.title !== null) this.document.title = definition.client.title;
            this.updateHead(definition.client.head);
        }
        this.arrive(url, options);
        return early;
    }

    /**
     * The visit's answer: the page's data (asked for with X-Fusion-Navigate: a Fusion page answers
     * with it, any other page with its HTML), or its HTML. Neither for an error, a download or a
     * failed network: the browser's own load says what it is. id: the page rendered early, which
     * the server's state keeps (X-Fusion-Id).
     */
    async request(url, data, id = null) {
        const answer = { url, page: null, html: null };
        try {
            const response = await visit(this.runtime, url.href, data, id);
            answer.url = new URL(response.url || url.href);
            const type = response.headers.get('Content-Type') ?? '';
            if (response.ok && type.includes('application/json')) answer.page = await response.json();
            else if (response.ok && type.includes('text/html')) answer.html = await response.text();
        } catch {
            // the network failed
        }
        return answer;
    }

    /**
     * The new page's head tags (@fusionHead's: description, link-preview tags, canonical, robots)
     * in place of the last page's. None sent (a layout without @fusionHead): left as they are.
     */
    updateHead(html) {
        if (typeof html !== 'string') return;
        for (const tag of this.document.head.querySelectorAll('[data-fusion-head]')) tag.remove();
        const template = this.document.createElement('template');
        template.innerHTML = html;
        this.document.head.append(...template.content.children);
    }

    /** Leaving the page for target: where it was scrolled to, and its #[Remember] values, for the back button. */
    leave(target) {
        this.saveRemembered();
        this.rememberScroll();
        this.window.history.pushState({}, '', target.href);
    }

    /** On the new page: the remembered scroll position, the current one kept, or the #hash or the top. */
    arrive(target, options) {
        if (options.scroll) {
            this.window.scrollTo(options.scroll.x, options.scroll.y);
        } else if (!options.preserveScroll) {
            this.scroll(target);
        }
        this.current();
        this.runtime.emit('fusion:navigated', { url: this.window.location.href });
    }

    /** To the #hash's element, or the top. */
    scroll(url) {
        try {
            const anchor = url.hash ? this.document.getElementById(decodeURIComponent(url.hash.slice(1))) : null;
            anchor ? anchor.scrollIntoView() : this.window.scrollTo(0, 0);
        } catch {
            // not available (a non-visual environment)
        }
    }

    /**
     * #[Url]: the component's properties that changed, written into the query string (a history
     * entry with history: true, else the current one replaced). A value equal to the declared
     * default (or to except) is left out, unless keep.
     */
    syncUrl(component, changed) {
        const url = new URL(this.window.location.href);
        let push = false;
        for (const name of changed) {
            const prop = component.definition.props[name];
            if (!prop?.url) continue;
            const key = prop.url.as;
            for (const existing of [...url.searchParams.keys()]) {
                if (existing === key || existing.startsWith(key + '[')) url.searchParams.delete(existing);
            }
            const value = component.data.get(name) ?? null;
            if (!omitted(value, prop)) appendQuery(url.searchParams, key, value);
            if (prop.url.history) push = true;
        }
        if (url.href === this.window.location.href) return;
        this.window.history[push ? 'pushState' : 'replaceState'](this.window.history.state ?? {}, '', url.href);
    }

    /** fusion:current: mark the links pointing at this page. */
    current() {
        const path = this.window.location.pathname.replace(/\/+$/, '') || '/';
        for (const link of this.document.querySelectorAll('[fusion\\:current], [fusion\\:current\\.exact]')) {
            const exact = link.hasAttribute('fusion:current.exact');
            const classes = (link.getAttribute(exact ? 'fusion:current.exact' : 'fusion:current') || 'active').split(/\s+/).filter(Boolean);
            const target = new URL(link.getAttribute('href') ?? '', this.window.location.href).pathname.replace(/\/+$/, '') || '/';
            const on = exact || target === '/' ? path === target : path === target || path.startsWith(target + '/');
            classes.forEach((c) => link.classList.toggle(c, on));
            on ? link.setAttribute('aria-current', 'page') : link.removeAttribute('aria-current');
        }
    }
}

/** A fusion:navigate link's modifiers (keypress, preserve-scroll, prefetch, visible, transition, a duration); null for another link. */
function navigateModifiers(link) {
    const attribute = [...link.attributes].find((a) => a.name === 'fusion:navigate' || a.name.startsWith('fusion:navigate.'));
    return attribute ? attribute.name.split('.').slice(1) : null;
}

/**
 * Whether a #[Url] value stays out of the URL: null; or equal to except when it has one, else to
 * the declared default (unless keep). Compared as encoded, so types count (1 is not "1").
 */
function omitted(value, prop) {
    if (value === null) return true;
    const encoded = JSON.stringify(P.encode(value));
    if ('except' in prop.url) return encoded === JSON.stringify(prop.url.except);
    return !prop.url.keep && 'default' in prop && encoded === JSON.stringify(prop.default);
}

/** A value as PHP reads it back from a query string: key=v, key[]=v for lists, key[k]=v for maps. */
function appendQuery(params, key, value) {
    if (P.isArray(value)) {
        const list = Array.isArray(value);
        for (const [k, v] of P.entries(value)) appendQuery(params, list ? `${key}[]` : `${key}[${k}]`, v);
        return;
    }
    params.append(key, value === true ? '1' : value === false ? '0' : P.toStr(value));
}
