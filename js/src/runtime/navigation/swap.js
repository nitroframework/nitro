/**
 * nitro:navigate to a page Nitro cannot render itself: a Blade view, a controller's view, a page
 * in another layout, or from such a page. Its HTML is fetched and put in place of the current
 * document's, as wire:navigate and Turbo do: the runtime and app.js stay loaded, the components on
 * the old page are destroyed, and those on the new one (islands too) start as on a page load.
 *
 * The head is merged: new stylesheets are added and waited for (no flash of an unstyled page),
 * what both pages have stays, and other elements the new page does not have (meta, canonical
 * links) go. Stylesheets and scripts are never taken away: what a script did cannot be undone.
 * The new page's scripts run, except Nitro's own (@nitroScripts) and those an earlier page
 * loaded already.
 *
 * A page with another build of Nitro (a deploy since this one loaded), or without
 * @nitroScripts, loads normally.
 *
 * Nested layouts: a layout marks where its pages go with nitro:layout="name" on the element
 * around its slot. When both pages have the same layouts, outer to inner, only the innermost
 * one they share changes its content: what is around it (a sidebar, its scroll, its components)
 * stays as it was. With none in common, the whole body is replaced.
 */
import { session } from '../../laravel/blade.js';
import { globals } from '../../laravel/laravel.js';
import { configOf, globalsOf, isPageDataScript, isRuntimeScript, readStates, runtimeScriptOf } from '../page-data.js';
import { ATTRIBUTES, selector } from '../protocol.js';

const lasting = (el) => el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || (el.tagName === 'LINK' && /\bstylesheet\b/i.test(el.rel));

export class Swap {
    constructor(runtime, window) {
        this.runtime = runtime;
        this.window = window;
        this.document = window.document;
        /** The scripts (by URL) already run here, those of the pages before this one too. */
        this.loaded = new Set([...this.document.querySelectorAll('script[src]')].map((script) => script.src));
        /**
         * The body's nodes that came with the page's HTML. A swap replaces those; what a script
         * added to the body (a progress bar, Nitro's or the application's own, a chat widget)
         * stays where it is.
         */
        this.pageNodes = new WeakSet();
        const record = () => {
            for (const node of this.document.body?.childNodes ?? []) this.pageNodes.add(node);
        };
        if (this.document.readyState === 'loading') this.document.addEventListener('DOMContentLoaded', record, { once: true });
        else record();
    }

    /** The page's HTML parsed, or null when it cannot replace this one (it loads normally then). */
    read(html, url) {
        const page = new this.window.DOMParser().parseFromString(html, 'text/html');
        /** Nitro's runtime, as @nitroScripts writes it: the same build, from the same place. */
        const theirs = runtimeScriptOf(page);
        const ours = runtimeScriptOf(this.document);
        if (!theirs || !page.body) return null;
        if (configOf(theirs).bundle !== (this.runtime.config.bundle ?? '')) return null;
        if (ours && new URL(theirs.getAttribute('src') ?? '', url).href !== ours.src) return null;
        return page;
    }

    /**
     * Put the page in place of the current one: its new stylesheets loaded, the head merged, the
     * body replaced, its scripts run and its components started. `before` runs just before the
     * old page goes (the history entry). Resolves when the page's components are live.
     */
    async replace(page, url, before) {
        const current = new Map([...this.document.head.children].map((el) => [el.outerHTML, el]));
        const keep = new Set();
        const adding = [];
        for (const el of page.head.children) {
            if (el.tagName === 'TITLE' || (el.tagName === 'SCRIPT' && this.nitros(el, url))) continue;
            const same = current.get(el.outerHTML);
            same ? keep.add(same) : adding.push(el);
        }

        /** The new stylesheets first, waited for: the page does not show unstyled. */
        const added = new Set();
        const styles = adding.filter((el) => el.tagName === 'LINK' && lasting(el));
        await Promise.all(styles.map((el) => {
            const link = this.document.importNode(el, true);
            added.add(link);
            const loaded = new Promise((resolve) => {
                link.addEventListener('load', resolve, { once: true });
                link.addEventListener('error', resolve, { once: true });
                this.window.setTimeout(resolve, 3000);
            });
            this.document.head.appendChild(link);
            return loaded;
        }));

        before();

        /** The layout both pages have, innermost: only its content changes. */
        const shared = this.sharedLayout(page);

        /** The old page's components go; Nitro's data for the new page (routes, shared, flash) comes. */
        this.runtime.forget(shared ? shared.ours : this.document.body);
        /** Unreadable: the page's components read what there was. */
        Object.assign(globals, globalsOf(page) ?? {});
        readStates(page);
        const { csrf } = configOf(runtimeScriptOf(page));
        if (csrf) session.token = csrf;

        for (const el of adding) {
            if (styles.includes(el)) continue;
            const copy = el.tagName === 'SCRIPT' ? this.script(el, url) : this.document.importNode(el, true);
            added.add(copy);
            this.document.head.appendChild(copy);
        }
        for (const el of [...this.document.head.children]) {
            if (!lasting(el) && !keep.has(el) && !added.has(el)) el.remove();
        }
        if (page.title) this.document.title = page.title;

        if (shared) {
            const region = this.document.adoptNode(shared.theirs);
            for (const script of region.querySelectorAll('script')) {
                if (!this.nitros(script, url)) script.replaceWith(this.script(script, url));
            }
            for (const { name } of [...shared.ours.attributes]) shared.ours.removeAttribute(name);
            for (const { name, value } of [...region.attributes]) shared.ours.setAttribute(name, value);
            shared.ours.replaceChildren(...region.childNodes);
            return this.runtime.start(shared.ours);
        }

        const body = this.document.adoptNode(page.body);
        for (const script of body.querySelectorAll('script')) {
            if (!this.nitros(script, url)) script.replaceWith(this.script(script, url));
        }
        /**
         * The body is kept and the page's content in it replaced: what a script added stays where
         * it is, so a progress bar finishes. Moved to a new parent, its running transition would stop.
         */
        const kept = this.document.body;
        for (const node of [...kept.childNodes]) {
            if (this.pageNodes.has(node)) node.remove();
        }
        for (const { name } of [...kept.attributes]) kept.removeAttribute(name);
        for (const { name, value } of [...body.attributes]) kept.setAttribute(name, value);
        const nodes = [...body.childNodes];
        kept.prepend(...nodes);
        for (const node of nodes) this.pageNodes.add(node);

        return this.runtime.start(kept);
    }

    /**
     * The innermost layout region both pages have: their nitro:layout elements, outer to inner,
     * the same names in the same nesting. Null when the first ones differ (the whole body changes).
     */
    sharedLayout(page) {
        const ours = [...this.document.body.querySelectorAll(selector('layout'))];
        const theirs = [...page.body.querySelectorAll(selector('layout'))];
        let shared = null;
        for (let i = 0; i < Math.min(ours.length, theirs.length); i++) {
            if (ours[i].getAttribute(ATTRIBUTES.layout) !== theirs[i].getAttribute(ATTRIBUTES.layout)) break;
            /** A chain: each inside the one before it (sibling regions are not nesting). */
            if (shared && (!shared.ours.contains(ours[i]) || !shared.theirs.contains(theirs[i]))) break;
            shared = { ours: ours[i], theirs: theirs[i] };
        }
        return shared;
    }

    /** @nitroScripts' own: the globals, the runtime, app.js and the chunks (the runtime loads those it needs). */
    nitros(script, url) {
        if (isPageDataScript(script) || isRuntimeScript(script)) return true;
        const src = script.getAttribute('src');
        if (!src) return false;
        const chunks = new URL(this.runtime.config.chunks, url).href;
        const prefix = chunks.split(/\{id\}|%7Bid%7D/i)[0];
        return prefix !== chunks && new URL(src, url).href.startsWith(prefix);
    }

    /**
     * A script of the new page as one the browser runs (a parsed one never does), in the page's
     * order; one an earlier page loaded is not loaded again, an inline one runs each time.
     */
    script(el, url) {
        const src = el.getAttribute('src');
        const type = (el.getAttribute('type') ?? '').trim().toLowerCase();
        const runs = type === '' || type === 'module' || /^(text|application)\/(x-)?(java|ecma)script$/.test(type);
        if (!runs || (src && this.loaded.has(new URL(src, url).href))) return this.document.importNode(el, true);

        const script = this.document.createElement('script');
        for (const { name, value } of el.attributes) script.setAttribute(name, value);
        script.textContent = el.textContent;
        if (src) {
            if (!el.hasAttribute('async')) script.async = false;
            this.loaded.add(new URL(src, url).href);
        }
        return script;
    }
}
