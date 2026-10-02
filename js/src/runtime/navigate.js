/**
 * fusion:navigate: links that change the page without reloading it, rendered in the browser.
 * The visit asks the URL itself for the page's data (X-Fusion-Navigate), so Laravel still routes
 * it, with its middleware, bindings and redirects; a Route::fusion() page answers with its
 * component's signed state and title, and the browser renders the compiled component in place of
 * the current page (the element marked fusion:page). The rest of the layout, and its state, stays.
 * A #[LazyLoad] page's chunk loads during the visit, alongside its data (Runtime::preload()).
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
import * as P from '../php.js';
import { Swap } from './swap.js';

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

    /**
     * The fusion:navigate link an event is on, where it goes and its modifiers (keypress,
     * preserve-scroll); null when the browser should handle it.
     */
    target(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return null;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        const attribute = link ? [...link.attributes].find((a) => a.name === 'fusion:navigate' || a.name.startsWith('fusion:navigate.')) : null;
        if (!attribute) return null;
        if (link.target && link.target !== '_self') return null;
        const url = new URL(link.href, this.window.location.href);
        const modifiers = attribute.name.split('.').slice(1);
        return url.origin === this.window.location.origin ? { link, url, modifiers } : null;
    }

    press(event) {
        this.pressed = null;
        if (event.pointerType === 'touch') return;
        const found = this.target(event);
        if (!found || !found.modifiers.includes('keypress')) return;
        this.pressed = found.link;
        this.visit(found.url.href, true, { preserveScroll: found.modifiers.includes('preserve-scroll') });
    }

    click(event) {
        const found = this.target(event);
        if (!found) return;
        event.preventDefault();
        if (this.pressed === found.link) {
            this.pressed = null;
            return;
        }
        this.visit(found.url.href, true, { preserveScroll: found.modifiers.includes('preserve-scroll') });
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
        const remembered = this.runtime.remembered();
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
        const url = new URL(href, this.window.location.href);
        if (url.origin !== this.window.location.origin) {
            this.window.location.href = url.href;
            return;
        }
        /** The page Fusion renders into; none on a page that is not Fusion's (its HTML comes then). */
        const current = this.document.querySelector('[fusion\\:page]');

        const visit = (this.visiting = {});
        this.runtime.emit('fusion:navigating', { url: url.href });
        /** A #[LazyLoad] page's chunk loads while its data does. */
        this.runtime.preload(url.pathname);

        /** The page's data (a Fusion page answers with it, any other with its HTML); or its HTML. */
        let answer = await this.fetch(url, current !== null);
        if (visit !== this.visiting) return; // a newer visit started

        /** A Fusion page in another layout: its HTML. */
        if (answer.page?.fusion === 1 && answer.page.layout !== current?.getAttribute('fusion:page')) {
            answer = await this.fetch(answer.url, false);
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
            await this.swap.replace(html, target, () => push && this.leave(target));
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

        if (push) this.leave(target);
        /** Fusion::share()'s data for this page, before it renders (components outside it follow). */
        this.runtime.receive(page, null, true);
        if (!this.runtime.mountPage(current, page)) {
            this.window.location.reload();
            return;
        }
        if (page.title !== null) this.document.title = page.title;
        /** Back or forward: what #[Remember] properties had in that entry. */
        if (!push) this.runtime.restoreRemembered(this.window.history.state?.fusionRemember);
        this.arrive(target, options);
    }

    /**
     * The visit's answer: the page's data (asked for with X-Fusion-Navigate: a Fusion page answers
     * with it, any other page with its HTML), or its HTML. Neither for an error, a download or a
     * failed network: the browser's own load says what it is.
     */
    async fetch(url, data) {
        const answer = { url, page: null, html: null };
        try {
            const response = await this.window.fetch(url.href, {
                headers: data
                    ? { Accept: 'application/json', 'X-Fusion-Navigate': '1', 'X-Fusion-Version': this.runtime.config.bundle ?? '', 'X-Fusion-Once': this.runtime.onceHeader() }
                    : { Accept: 'text/html, application/xhtml+xml' },
                credentials: 'same-origin',
            });
            answer.url = new URL(response.url || url.href);
            const type = response.headers.get('Content-Type') ?? '';
            if (response.ok && type.includes('application/json')) answer.page = await response.json();
            else if (response.ok && type.includes('text/html')) answer.html = await response.text();
        } catch {
            // the network failed
        }
        return answer;
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
