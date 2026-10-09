/**
 * The overlay: a badge in a corner, and a drawer with a tab for each thing it shows. Everything
 * lives in a shadow root (the page's CSS and its own never meet) and is kept as it comes (the
 * plugin's events, nitro:error); what the open tab shows is rendered again when it changes.
 */
import { pageDataSizes } from '../runtime/page-data.js';
import { ATTRIBUTES, selector } from '../runtime/protocol.js';
import { STYLE } from './style.js';

const TABS = ['Components', 'Requests', 'Page', 'Stores', 'Errors'];
/** The requests and errors kept: the latest ones. */
const KEEP = 100;

export class Overlay {
    constructor(window, nitro) {
        this.window = window;
        this.document = window.document;
        this.nitro = nitro;
        this.requests = [];
        this.errors = [];
        this.stores = new Map();
        this.tab = 'Components';
        this.open = false;
        this.selected = null;
        this.pending = false;
    }

    install() {
        this.nitro.use(({ on }) => {
            on('component', () => this.changed());
            on('render', () => this.changed('Components'));
            on('store', (store) => {
                this.stores.set(store.name, store);
                store.onChange(() => this.changed('Stores'));
                this.changed('Stores');
            });
            on('response', (response) => {
                this.requests.unshift({ at: new Date(), ...response, component: response.component?.name ?? null });
                this.requests.length = Math.min(this.requests.length, KEEP);
                this.changed('Requests');
            });
        });
        this.window.addEventListener('nitro:error', (event) => {
            this.errors.unshift({ at: new Date(), ...event.detail });
            this.errors.length = Math.min(this.errors.length, KEEP);
            this.changed('Errors');
        });

        const host = this.document.createElement('nitro-devtools');
        this.root = host.attachShadow({ mode: 'open' });
        this.root.innerHTML = `<style>${STYLE}</style><button class="badge" title="Nitro"></button><section class="drawer" hidden></section>`;
        this.badge = this.root.querySelector('.badge');
        this.drawer = this.root.querySelector('.drawer');
        this.badge.addEventListener('click', () => this.toggle());
        this.drawer.addEventListener('click', (event) => this.click(event));
        this.drawer.addEventListener('mouseover', (event) => this.outline(event));
        this.drawer.addEventListener('mouseleave', () => this.outline(null));
        this.document.body.appendChild(host);
        this.render();
    }

    toggle() {
        this.open = !this.open;
        this.render();
    }

    /** Something a tab shows changed: rendered again once per frame, if that tab is open. */
    changed(tab = null) {
        if (this.pending) return;
        this.pending = true;
        this.window.requestAnimationFrame(() => {
            this.pending = false;
            if (tab === null || tab === this.tab || tab === 'Errors') this.render();
        });
    }

    click(event) {
        const tab = event.target.closest('[data-tab]');
        if (tab) {
            this.tab = tab.dataset.tab;
            this.render();
            return;
        }
        const row = event.target.closest('[data-component]');
        if (row) {
            this.selected = this.selected === row.dataset.component ? null : row.dataset.component;
            this.render();
            return;
        }
        if (event.target.closest('[data-close]')) this.toggle();
    }

    /** A component's row under the pointer: its element outlined in the page. */
    outline(event) {
        this.outlined?.style.removeProperty('outline');
        this.outlined = null;
        const id = event?.target.closest?.('[data-component]')?.dataset.component;
        const el = id ? this.nitro.find(id)?.el : null;
        if (el?.style) {
            el.style.outline = '2px solid #e11d48';
            this.outlined = el;
        }
    }

    render() {
        const components = this.nitro.components();
        this.badge.innerHTML = `<b>N</b> ${components.length}${this.errors.length ? ` <i>${this.errors.length}</i>` : ''}`;
        this.drawer.hidden = !this.open;
        if (!this.open) return;

        const tabs = TABS.map((tab) => `<button data-tab="${tab}" class="${tab === this.tab ? 'current' : ''}">${tab}${count(this, tab)}</button>`).join('');
        this.drawer.innerHTML = `<nav>${tabs}<button data-close title="Close">×</button></nav><div class="body">${this[`render${this.tab}`]()}</div>`;
    }

    renderComponents() {
        const rows = this.nitro.components().map((component) => {
            const depth = depthOf(component);
            const open = this.selected === component.id;
            const state = open ? `<tr class="state"><td colspan="3"><pre>${escape(json(plain(component.data)))}</pre>${errorsOf(component)}</td></tr>` : '';
            return `<tr data-component="${escape(component.id)}" class="${open ? 'current' : ''}"><td style="padding-left:${0.5 + depth}rem">${escape(component.name)}</td><td>${escape(component.id)}</td><td>${component.data.size} properties</td></tr>${state}`;
        });
        return rows.length ? `<table><tr><th>Component</th><th>Id</th><th>State</th></tr>${rows.join('')}</table><p class="hint">Point at a row to outline it in the page; click it for its state.</p>` : empty('No components on this page.');
    }

    renderRequests() {
        if (!this.requests.length) return empty('No requests yet: a #[Server] call, a reload or an upload shows here.');
        const rows = this.requests.map((r) => `<tr class="${r.status >= 400 || r.status === 0 ? 'bad' : ''}"><td>${time(r.at)}</td><td>${escape(r.component ?? '')}</td><td>${escape(r.method ?? '$refresh')}</td><td>${r.status || 'failed'}</td><td>${ms(r.ms)}</td><td>${ms(r.serverMs)}</td><td>${ms(r.dbMs)}</td><td>${size(r.bytes)}</td></tr>`);
        return `<table><tr><th>At</th><th>Component</th><th>Method</th><th>Status</th><th>Total</th><th>Server</th><th>Database</th><th>Answer</th></tr>${rows.join('')}</table>`;
    }

    renderPage() {
        const page = this.document.querySelector(selector('page'));
        const sizes = pageDataSizes(this.document);
        const states = Object.entries(sizes.states).sort((a, b) => b[1] - a[1]);
        const total = states.reduce((sum, [, bytes]) => sum + bytes, 0);
        const rows = states.map(([id, bytes]) => `<tr data-component="${escape(id)}"><td>${escape(this.nitro.find(id)?.name ?? '')}</td><td>${escape(id)}</td><td>${size(bytes)}</td></tr>`);
        return `<dl><dt>URL</dt><dd>${escape(this.window.location.pathname + this.window.location.search)}</dd>`
            + `<dt>Layout</dt><dd>${page ? escape(page.getAttribute(ATTRIBUTES.page)) : 'not a Nitro page'}</dd>`
            + `<dt>Page data</dt><dd>${size(total + sizes.globals)}: the state of ${states.length} components ${size(total)}, the globals ${size(sizes.globals)}</dd></dl>`
            + (rows.length ? `<table><tr><th>Component</th><th>Id</th><th>Its state</th></tr>${rows.join('')}</table>` : '');
    }

    renderStores() {
        if (!this.stores.size) return empty('No stores on this page.');
        return [...this.stores.values()].map((store) => `<h3>${escape(store.name)}${store.persist?.length ? ' <small>#[Persist]</small>' : ''}${store.sync?.length ? ' <small>#[Sync]</small>' : ''}</h3><pre>${escape(json(storeState(store)))}</pre>`).join('');
    }

    renderErrors() {
        if (!this.errors.length) return empty('No errors.');
        return this.errors.map((e) => `<article class="error"><header>${time(e.at)} ${escape(e.component ?? 'Nitro')}${e.status !== undefined ? ` · ${e.status}` : ''}</header><pre>${escape(e.message)}</pre>${e.body ? `<details><summary>The answer</summary><pre>${escape(String(e.body).slice(0, 4000))}</pre></details>` : ''}</article>`).join('');
    }
}

function count(overlay, tab) {
    const n = { Requests: overlay.requests.length, Stores: overlay.stores.size, Errors: overlay.errors.length }[tab];
    return n ? ` <small>${n}</small>` : '';
}

function depthOf(component) {
    let depth = 0;
    for (let parent = component.parent; parent; parent = parent.parent) depth++;
    return depth;
}

function errorsOf(component) {
    const messages = component.errors?.all?.() ?? [];
    return messages.length ? `<p class="bad">${messages.map(escape).join('<br>')}</p>` : '';
}

/** A component's or a store's values as plain JSON: Maps (PHP's associative arrays) as objects. */
function plain(value) {
    if (value instanceof Map) return Object.fromEntries([...value].map(([k, v]) => [k, plain(v)]));
    if (Array.isArray(value)) return value.map(plain);
    if (value && typeof value === 'object' && value.constructor === Object) return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, plain(v)]));
    return value;
}

function storeState(store) {
    try {
        return store.values();
    } catch {
        return '(not readable)';
    }
}

function json(value) {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

function escape(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

function empty(text) {
    return `<p class="empty">${escape(text)}</p>`;
}

function time(date) {
    return date.toLocaleTimeString();
}

function ms(value) {
    return value === undefined || value === null ? '' : `${Math.round(value * 10) / 10} ms`;
}

function size(bytes) {
    if (bytes === undefined || bytes === null) return '';
    return bytes < 1024 ? `${bytes} B` : `${Math.round(bytes / 102.4) / 10} KB`;
}
