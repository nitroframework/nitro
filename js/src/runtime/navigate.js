/**
 * fusion:navigate: links that change the page without reloading it, rendered in the browser.
 * The visit asks the URL itself for the page's data (X-Fusion-Navigate), so Laravel still routes
 * it, with its middleware, bindings and redirects; a Route::fusion() page answers with its
 * component's signed state and title, and the browser renders the compiled component in place of
 * the current page (the element marked fusion:page). The rest of the layout, and its state, stays.
 * A #[LazyLoad] page's chunk loads during the visit, alongside its data (Runtime::preload()).
 * Anything else (another layout, a page that is not Fusion's, an error) loads normally.
 *
 * fusion:navigate.keypress starts the visit as the mouse button goes down, not when it comes back
 * up (the click): the page is on its way during the press. The click that follows is the same
 * visit. Touch keeps the tap (a finger going down may be a scroll), and so does the keyboard.
 *
 * fusion:current="active" on a link adds the class while the link points at the current page
 * (fusion:current.exact: the same path only).
 *
 * #[Url] properties are kept in the query string (syncUrl()); the server reads them back when it
 * renders the page, so a reload, a shared link and back/forward show the same state.
 */
import * as P from '../php.js';

export class Navigator {
    constructor(runtime, window) {
        this.runtime = runtime;
        this.window = window;
        this.document = window.document;
        this.visiting = null;
        /** The link a press already started a visit for, until its click has come and gone. */
        this.pressed = null;

        this.document.addEventListener('pointerdown', (e) => this.press(e));
        this.document.addEventListener('pointerup', () => this.window.setTimeout(() => (this.pressed = null)));
        this.document.addEventListener('click', (e) => this.click(e));
        window.addEventListener('popstate', () => this.visit(window.location.href, false));
    }

    /** The fusion:navigate link an event is on, and where it goes; null when the browser should handle it. */
    target(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return null;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || !(link.hasAttribute('fusion:navigate') || link.hasAttribute('fusion:navigate.keypress'))) return null;
        if (link.target && link.target !== '_self') return null;
        const url = new URL(link.href, this.window.location.href);
        return url.origin === this.window.location.origin ? { link, url } : null;
    }

    press(event) {
        this.pressed = null;
        if (event.pointerType === 'touch') return;
        const found = this.target(event);
        if (!found || !found.link.hasAttribute('fusion:navigate.keypress')) return;
        this.pressed = found.link;
        this.visit(found.url.href);
    }

    click(event) {
        const found = this.target(event);
        if (!found) return;
        event.preventDefault();
        if (this.pressed === found.link) {
            this.pressed = null;
            return;
        }
        this.visit(found.url.href);
    }

    async visit(href, push = true) {
        const url = new URL(href, this.window.location.href);
        const current = this.document.querySelector('[fusion\\:page]');
        if (!current || url.origin !== this.window.location.origin) {
            this.window.location.href = url.href;
            return;
        }

        const visit = (this.visiting = {});
        this.runtime.emit('fusion:navigating', { url: url.href });
        /** A #[LazyLoad] page's chunk loads while its data does. */
        this.runtime.preload(url.pathname);

        let response = null;
        let page = null;
        try {
            response = await fetch(url.href, { headers: { Accept: 'application/json', 'X-Fusion-Navigate': '1' }, credentials: 'same-origin' });
            if (response.ok && (response.headers.get('Content-Type') ?? '').includes('application/json')) {
                page = await response.json();
            }
        } catch {
            // the network failed: the browser's own load says why
        }
        if (visit !== this.visiting) return; // a newer visit started

        /** Where the visit ended up (after redirects), with the link's #hash. */
        const target = new URL(response?.url || url.href);
        target.hash = url.hash;

        if (page?.fusion !== 1 || page.layout !== current.getAttribute('fusion:page')) {
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

        if (push) this.window.history.pushState({}, '', target.href);
        /** Fusion::share()'s data for this page, before it renders (components outside it follow). */
        if (page.shared !== undefined) this.runtime.setShared(page.shared);
        if (!this.runtime.mountPage(current, page)) {
            this.window.location.reload();
            return;
        }
        if (page.title !== null) this.document.title = page.title;
        this.scroll(target);
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
