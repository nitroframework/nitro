// End-to-end: the fixture app served by `php -S`, pages loaded in jsdom with the real runtime and
// compiled components, driven like a user. stdin {url}; stdout a list of failed checks.
import { JSDOM, ResourceLoader, CookieJar, VirtualConsole } from 'jsdom';
import { readStdin } from '../support/common.mjs';

const { url, ssr } = await readStdin();
const failures = [];
const log = [];
/** console.error lines a scenario causes on purpose. */
const expectedErrors = [];

process.on('unhandledRejection', (reason) => failures.push(`unhandled rejection: ${reason?.stack ?? reason}`));

function check(label, condition, detail = '') {
    if (!condition) failures.push(`${label}${detail ? `: ${detail}` : ''}`);
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function until(label, condition, timeout = 3000) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
        try {
            if (condition()) return true;
        } catch {
            // not there yet
        }
        await wait(15);
    }
    failures.push(`timed out: ${label}`);
    return false;
}

const cookieJar = new CookieJar();

/**
 * `php -S` answers one request at a time (on Windows always): a connection can be refused while it
 * is busy. Not what is under test, so a load that could not connect is tried again.
 */
async function retried(load, tries = 4) {
    for (let attempt = 1; ; attempt++) {
        try {
            return await load();
        } catch (error) {
            if (attempt >= tries) throw error;
            await wait(100 * attempt);
        }
    }
}

/** Scripts and other resources of the page, tried again when the server could not be reached. */
class Resources extends ResourceLoader {
    fetch(url, options) {
        const load = () => ResourceLoader.prototype.fetch.call(this, url, options);
        let request = load();
        if (!request) return request;
        const result = (async () => {
            for (let attempt = 1; ; attempt++) {
                try {
                    return await request;
                } catch (error) {
                    if (attempt >= 4) throw error;
                    await wait(100 * attempt);
                    request = load();
                }
            }
        })();
        /** jsdom reads the request's response, URL and headers from what it gets back: the current try's. */
        Object.defineProperties(result, {
            response: { get: () => request.response },
            href: { get: () => request.href },
        });
        result.getHeader = (name) => request.getHeader(name);
        result.abort = () => request.abort?.();
        return result;
    }
}

/** The browser's fetch, with the page's cookies (jsdom has none of its own). */
function browserFetch(window) {
    return async (input, init = {}) => {
        const target = new URL(input, window.location.href);
        const headers = new Headers(init.headers ?? {});
        const cookies = cookieJar.getCookieStringSync(target.href);
        if (cookies) headers.set('Cookie', cookies);
        headers.set('Sec-Fetch-Site', 'same-origin');
        /** A request that could not connect is tried again, if sending it twice does nothing (GET). */
        const method = (init.method ?? 'GET').toUpperCase();
        const send = () => fetch(target, { ...init, headers, redirect: 'follow' });
        const response = method === 'GET' || method === 'HEAD' ? await retried(send) : await send();
        for (const cookie of response.headers.getSetCookie?.() ?? []) cookieJar.setCookieSync(cookie, target.href);
        return response;
    };
}

/** A localStorage several windows share, as a browser's tabs of one site do. */
class SharedStorage {
    constructor() { this.items = new Map(); }
    get length() { return this.items.size; }
    key(i) { return [...this.items.keys()][i] ?? null; }
    getItem(key) { return this.items.has(String(key)) ? this.items.get(String(key)) : null; }
    setItem(key, value) { this.items.set(String(key), String(value)); }
    removeItem(key) { this.items.delete(String(key)); }
    clear() { this.items.clear(); }
}

/** Node's BroadcastChannel, for windows that are a browser's tabs: closed once the scenario is over. */
const channels = [];
class TabChannel extends BroadcastChannel {
    constructor(name) {
        super(name);
        channels.push(this);
    }
}

/** options: { localStorage, tabs } (tabs: the window has BroadcastChannel, which jsdom has not). */
async function open(path, options = {}) {
    const virtualConsole = new VirtualConsole();
    virtualConsole.on('error', (...args) => log.push(args.map(String).join(' ')));
    virtualConsole.on('jsdomError', (error) => log.push(`jsdom: ${error.message}`));

    const dom = await retried(() => JSDOM.fromURL(url + path, {
        runScripts: 'dangerously',
        resources: new Resources(),
        pretendToBeVisual: true,
        cookieJar,
        virtualConsole,
        beforeParse(window) {
            window.fetch = browserFetch(window);
            options.beforeParse?.(window);
            if (options.localStorage) Object.defineProperty(window, 'localStorage', { value: options.localStorage, configurable: true });
            if (options.tabs) window.BroadcastChannel = TabChannel;
            /** Browsers have these; jsdom does not. */
            window.TextEncoder = TextEncoder;
            window.TextDecoder = TextDecoder;
            /** A form-associated custom element's validity and value (Trix's <trix-editor>): jsdom's ElementInternals has neither. */
            const attachInternals = window.HTMLElement.prototype.attachInternals;
            window.HTMLElement.prototype.attachInternals = function () {
                const internals = attachInternals ? attachInternals.call(this) : {};
                for (const method of ['setValidity', 'setFormValue', 'checkValidity', 'reportValidity']) {
                    if (typeof internals[method] !== 'function') internals[method] = () => true;
                }
                return internals;
            };
            /** jsdom has no layout: an IntersectionObserver the test drives (window.observe(el, inView)). */
            const observers = [];
            window.IntersectionObserver = class {
                constructor(callback, options) {
                    this.callback = callback;
                    this.options = options;
                    this.elements = new Set();
                    observers.push(this);
                }
                observe(el) { this.elements.add(el); }
                unobserve(el) { this.elements.delete(el); }
                disconnect() { this.elements.clear(); }
            };
            /** A stand-in Laravel Echo: channels record their listeners; echoFire(type, channel, event, payload) delivers. */
            const channels = new Map();
            const channel = (type, name) => {
                const key = `${type}:${name}`;
                if (!channels.has(key)) {
                    channels.set(key, {
                        listeners: new Map(),
                        members: {},
                        notifications: [],
                        listen(event, callback) { this.listeners.set(event, [...(this.listeners.get(event) ?? []), callback]); return this; },
                        stopListening(event, callback) { this.listeners.set(event, (this.listeners.get(event) ?? []).filter((c) => c !== callback)); return this; },
                        here(callback) { this.members.here = callback; return this; },
                        joining(callback) { this.members.joining = callback; return this; },
                        leaving(callback) { this.members.leaving = callback; return this; },
                        notification(callback) { this.notifications.push(callback); return this; },
                        stopListeningForNotification(callback) { this.notifications = this.notifications.filter((c) => c !== callback); return this; },
                    });
                }
                return channels.get(key);
            };
            window.Echo = { channel: (name) => channel('public', name), private: (name) => channel('private', name), join: (name) => channel('presence', name), socketId: () => null };
            window.echoChannels = channels;
            window.echoFire = (type, name, event, payload) => {
                const found = channels.get(`${type}:${name}`);
                if (!found) return 0;
                if (event === 'notification') return found.notifications.forEach((c) => c(payload)) ?? found.notifications.length;
                if (type === 'presence' && ['here', 'joining', 'leaving'].includes(event)) return found.members[event]?.(payload) ?? 1;
                const listeners = found.listeners.get(event) ?? [];
                listeners.forEach((c) => c(payload));
                return listeners.length;
            };
            window.observe = (el, inView) => {
                for (const observer of observers) {
                    if (observer.elements.has(el)) observer.callback([{ target: el, isIntersecting: inView }], observer);
                }
            };
            window.observers = observers;

            /** jsdom has no layout: .mover rows stand 20px apart, in their order (absolute ones out of the flow). */
            const box = window.Element.prototype.getBoundingClientRect;
            window.Element.prototype.getBoundingClientRect = function () {
                if (!this.classList.contains('mover')) return box.call(this);
                const inFlow = [...this.parentNode.children].filter((el) => el.style.position !== 'absolute');
                const top = this.style.position === 'absolute' ? parseFloat(this.style.top) || 0 : inFlow.indexOf(this) * 20;
                return { top, left: 0, width: 100, height: 20, right: 100, bottom: top + 20, x: 0, y: top };
            };

            /** jsdom has no media queries: none match until the test says so (window.setMedia(query, matches)). */
            const media = new Map();
            window.matchMedia = (query) => {
                if (!media.has(query)) {
                    const listeners = new Set();
                    media.set(query, {
                        matches: false,
                        media: query,
                        addEventListener: (type, listener) => listeners.add(listener),
                        removeEventListener: (type, listener) => listeners.delete(listener),
                        listeners,
                    });
                }
                return media.get(query);
            };
            window.setMedia = (query, matches) => {
                const list = window.matchMedia(query);
                list.matches = matches;
                for (const listener of [...list.listeners]) listener({ matches, media: query });
            };

            /** Nitro::browser(): a clipboard, a canvas context that records its calls, and an echoing WebSocket. */
            let clipboardText = '';
            Object.defineProperty(window.navigator, 'clipboard', {
                value: { writeText: async (text) => void (clipboardText = text), readText: async () => clipboardText },
            });
            window.clipboardText = () => clipboardText;
            window.HTMLCanvasElement.prototype.getContext = function () {
                this.drawn ??= [];
                const drawn = this.drawn;
                return new Proxy({}, {
                    get: (target, name) => (name in target ? target[name] : (...args) => drawn.push([name, ...args])),
                    set: (target, name, value) => {
                        target[name] = value;
                        drawn.push([name, value]);
                        return true;
                    },
                });
            };
            window.sockets = [];
            window.WebSocket = class {
                constructor(url) {
                    this.url = url;
                    this.readyState = 0;
                    this.sent = [];
                    window.sockets.push(this);
                    setTimeout(() => {
                        this.readyState = 1;
                        this.onopen?.();
                    }, 5);
                }
                send(data) {
                    this.sent.push(data);
                    setTimeout(() => this.onmessage?.({ data }), 5);
                }
                close() {
                    if (this.readyState === 3) return;
                    this.readyState = 3;
                    setTimeout(() => this.onclose?.(), 0);
                }
            };
        },
    }));
    const { window } = dom;
    await until(`${path} loaded`, () => window.Nitro && window.document.readyState === 'complete' && window.Nitro.components().length > 0, 5000);
    await wait(30);
    return window;
}

function click(window, el) {
    el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
}

function type(window, el, value) {
    el.focus();
    el.value = value;
    el.dispatchEvent(new window.Event('input', { bubbles: true }));
}

function change(window, el) {
    el.dispatchEvent(new window.Event('change', { bubbles: true }));
}

/** The browser's names for components and chunks: an obfuscated build aliases them (/_test/names). */
let names = { components: {}, chunks: {}, methods: {}, properties: {} };
const P_ = (property) => names.properties[property] ?? property;
const N = (name) => names.components[name] ?? name;
const M = (method) => names.methods[method] ?? method;
const chunkId = (group) => names.chunks[group] ?? group;
const byName = (root, name) => root.querySelector(`[nitro\:name="${N(name)}"]`);

/** NITRO_E2E_ONLY="name|other name": only the scenarios whose name has one of these (while working on them). */
const only = process.env.NITRO_E2E_ONLY ? process.env.NITRO_E2E_ONLY.split('|') : null;

async function scenario(name, run) {
    if (only && !only.some((part) => name.includes(part))) return;
    try {
        await run();
    } catch (error) {
        failures.push(`${name} crashed: ${error.stack}`);
    }
}

const text = (el) => el?.textContent.replace(/\s+/g, ' ').trim();

// ------------------------------------------------------------------ counter and todos (/embed)
names = await fetch(url + '/_test/names').then((response) => response.json());

await scenario('embed', async () => {
    const window = await open('/embed');
    const { document } = window;
    const counter = byName(document, 'counter');
    const $ = (selector, root = counter) => root.querySelector(selector);

    check('hydrated: handlers attached', $('button').hasAttribute('nitro:h-click'));
    check('hydrated: no state in the DOM, the page\'s data is one block', !counter.hasAttribute('nitro:snapshot') && document.querySelectorAll('script[data-nitro-state]').length === 1);
    check('hydrated: model shows the state', $('input[type=number]').value === '2', $('input[type=number]').value);

    /** From here on a change renders only what reads it: never all of it. */
    const component = window.Nitro.find(counter.getAttribute('nitro:id'));
    let full = 0;
    for (const [target, method] of [[component.view, 'hydrate'], [component.view, 'create'], [component, 'rebuild']]) {
        const original = target[method].bind(target);
        target[method] = (...args) => {
            full++;
            return original(...args);
        };
    }
    const countText = $('.count').firstChild;
    const untouched = [...counter.querySelectorAll('button, input[type=number]')];
    const mutations = [];
    const observer = new window.MutationObserver((records) => mutations.push(...records));
    observer.observe(counter, { subtree: true, childList: true, attributes: true, characterData: true });

    const [minus, plus] = counter.querySelectorAll('button');
    click(window, plus);
    await until('counter to 2', () => text($('.count')) === '2');
    mutations.push(...observer.takeRecords());
    observer.disconnect();
    check('a change renders what reads it, not the component', full === 0, `${full} full renders`);
    check('the count is the same text node, its text set', $('.count').firstChild === countText && mutations.some((m) => m.type === 'characterData' && m.target === countText));
    check('what does not read the count is left alone', !mutations.some((m) => untouched.some((el) => el === m.target || el.contains(m.target))),
        mutations.filter((m) => untouched.some((el) => el.contains(m.target))).map((m) => `${m.type} ${m.target.nodeName}`).join(', '));
    check('the @if chain kept its branch\'s element', mutations.every((m) => m.type !== 'childList' || ['UL', 'LI'].includes(m.target.nodeName) || ['entries', 'raw'].includes(m.target.className)),
        mutations.filter((m) => m.type === 'childList').map((m) => m.target.nodeName).join(', '));
    check('computed method re-rendered', text($('p')) === 'Doubled: 4', text($('p')));
    check('loop rendered', text($('li')) === '1/1: 2', text($('li')));

    click(window, plus);
    click(window, plus);
    click(window, plus);
    await until('counter to 8', () => text($('.count')) === '8');
    check('@if branch switched', text($('strong')) === 'High!');
    check('autofocus on a field the render added', document.activeElement === $('input.why'), document.activeElement?.outerHTML);
    const items = counter.querySelectorAll('li');
    check('four history items', items.length === 4, String(items.length));
    check('$loop->last and $loop->even as @class', items[3].className === 'even last' && items[0].className === '', [...items].map((li) => li.className).join('|'));

    type(window, $('input[type=number]'), '5');
    await wait(20);
    click(window, minus);
    await until('nitro:model.number set the step', () => text($('.count')) === '3');

    /** Viewi's @foreach: the iterations still there keep their nodes; what reads $loop follows. */
    const rows = [...counter.querySelectorAll('li')];
    check('the iterations still there kept their nodes', rows.length === 5 && [...items].every((li, n) => rows[n] === li), String(rows.length));
    check('$loop moved on in them', rows[3].className === 'even' && rows[4].className === 'last' && text(rows[3]) === '4/5: 8', [...rows].map((li) => `${li.className}:${text(li)}`).join('|'));

    /** A value replaced by a write keeps its iteration (by its key): the field typed into stays. */
    const entries = () => [...counter.querySelectorAll('input.entry')];
    const entry = entries()[1];
    check('a field per item', entries().length === 5 && entry.value === '4', entries().map((e) => e.value).join(','));
    type(window, entry, '42');
    await until('the field wrote its item', () => text(rows[1]) === '2/5: 42');
    check('the row typed into kept its field and the focus', entries()[1] === entry && document.activeElement === entry && entry.value === '42');
    check('and its item its node', counter.querySelectorAll('li')[1] === rows[1]);

    let saved = null;
    window.addEventListener('counter-saved', (e) => (saved = e.detail.params));
    const save = counter.querySelectorAll('button')[2];
    click(window, save);
    /** The run's first #[Server] call: the test server boots the app cold (slowest on Laravel with server rendering, under a full suite's load). */
    await until('#[Server] save() round trip and its event', () => saved !== null, 10000);
    check('the event carried the server value', JSON.stringify(saved) === '[3]', JSON.stringify(saved));
    check('state kept after the round trip', text($('.count')) === '3');
    check('a snapshot with nothing new renders nothing again', full === 0, `${full} full renders`);
    check('nitro:loading.attr removed after the call', !save.hasAttribute('disabled'));

    /** Each @for button calls with its own $n, not the one the loop ended with. */
    const sets = counter.querySelectorAll('button.set');
    click(window, sets[1]);
    await until('the second @for button sets 20', () => text($('.count')) === '20');
    click(window, sets[0]);
    await until('the first @for button sets 10', () => text($('.count')) === '10');

    /** An answer that is not Nitro's (PHP printing a warning, say): nitro:error, not a crash. */
    const serverFetch = window.fetch;
    window.fetch = async () => new Response('<br /><b>Warning</b>: POST data can\'t be buffered', { status: 200 });
    let error = null;
    window.addEventListener('nitro:error', (e) => (error = e.detail), { once: true });
    click(window, save);
    await until('nitro:error for an answer that is not JSON', () => error !== null);
    window.fetch = serverFetch;
    check('the error says why', /answer is not JSON/.test(error?.message) && error?.status === 200 && /Warning/.test(error?.body), JSON.stringify(error));
    check('the button is usable again', !save.hasAttribute('disabled'));
    check('the browser state is kept', text($('.count')) === '10');
    expectedErrors.push(/answer is not JSON/);
    check('the nodes kept their identity through the @if above them', sets[0].textContent === '1' && counter.querySelectorAll('button.set')[0] === sets[0]);

    // ---------------------------------------------------------------- todos: children, events, validation
    const list = byName(document, 'todo-list');
    const q = (selector) => list.querySelector(selector);
    check('two server-rendered children', list.querySelectorAll('li.todo').length === 2);
    check('remaining', text(q('.remaining')) === '1 item left', text(q('.remaining')));
    check('@disabled while empty', q('button[type=submit]').hasAttribute('disabled'));

    type(window, q('input[type=text]'), 'Buy milk');
    await until('@disabled lifted by typing', () => !q('button[type=submit]').hasAttribute('disabled'));
    q('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    await until('a child created in the browser', () => list.querySelectorAll('li.todo').length === 3);
    const added = [...list.querySelectorAll('li.todo')].at(-1);
    check('the new child renders its props', text(added.querySelector('span')) === 'Buy milk', text(added));
    check('the new child is a component', added.hasAttribute('nitro:id') && window.Nitro.find(added.getAttribute('nitro:id')) !== null);
    check('input cleared through the model', q('input[type=text]').value === '', q('input[type=text]').value);
    check('remaining updated', text(q('.remaining')) === '2 items left', text(q('.remaining')));

    const first = list.querySelector('li.todo input[type=checkbox]');
    first.checked = true;
    change(window, first);
    await until('child event toggled the parent state', () => text(q('.remaining')) === '1 item left');
    check('the child re-rendered with new props', list.querySelector('li.todo').classList.contains('done'));

    click(window, [...list.querySelectorAll('nav button')].find((b) => text(b) === 'Done'));
    await until('$set filter', () => list.querySelectorAll('li.todo').length === 2);
    check('active filter class', text(list.querySelector('nav button.active')) === 'Done');
    click(window, [...list.querySelectorAll('nav button')].find((b) => text(b) === 'All'));
    await until('back to all', () => list.querySelectorAll('li.todo').length === 3);

    const removeButton = [...list.querySelectorAll('li.todo')].at(-1).querySelector('.remove');
    click(window, removeButton);
    await until('child removed itself through the parent', () => list.querySelectorAll('li.todo').length === 2);
    check('removed child component forgotten', window.Nitro.components().filter((c) => c.name === N('todo-item')).length === 2);

    type(window, q('input[type=text]'), 'This title is much too long to keep');
    q('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    await until('long todo added', () => list.querySelectorAll('li.todo').length === 3);
    click(window, [...list.querySelectorAll('button')].find((b) => text(b) === 'Save all'));
    await until('server validation error rendered', () => list.querySelector('.error') !== null);
    check('the message is the validator\'s', /must not be greater than 20 characters/.test(text(list.querySelector('.error'))), text(list.querySelector('.error')));
    check('the browser state survived the failed call', list.querySelectorAll('li.todo').length === 3);

    window.close();
});

// ------------------------------------------------------------------ a routed page, and navigation
await scenario('navigation', async () => {
    const window = await open('/students/1');
    const { document } = window;
    const page = document.querySelector('[nitro\\:page]');
    /** A chunk's script: by the route (/_nitro/app/<id>.js) or the build's static file (<id>-<version>.js). */
    const chunk = (group) => document.querySelector(`script[src*="/app/${chunkId(group)}.js"], script[src*="/${chunkId(group)}-"]`);

    /** #[LazyLoad]: the page's chunk came with the page; the others wait until a page needs them. */
    check('the page\'s chunk is listed by @nitroScripts', chunk('pages') !== null && window.Nitro.definitions.has(N('pages::student-page')));
    check('other chunks are not loaded', chunk('board') === null && !window.Nitro.definitions.has(N('pages::board')) && !window.Nitro.definitions.has(N('todo-item')));

    type(window, page.querySelector('input'), 'hello');
    await until('model re-renders the page', () => text(page.querySelector('.note')) === 'Note: hello');

    click(window, page.querySelector('button'));
    await until('#[Server] rename() updated the model', () => text(page.querySelector('h1')) === 'Renamed');
    check('a method on model data in the browser', text(page.querySelector('.shout')) === 'RENAMED!', text(page.querySelector('.shout')));
    check('browser state kept through the call', text(page.querySelector('.note')) === 'Note: hello');

    const link = document.querySelector('a[nitro\\:navigate]');
    check('nitro:current off elsewhere', !link.classList.contains('active'));
    click(window, link);
    await until('navigated without reloading', () => window.location.pathname === '/counter' && byName(document, 'pages::counter') !== null);
    check('title followed', document.title === 'Nitro', document.title);
    check('nitro:current on', link.classList.contains('active'));
    check('the old page\'s component is gone', !window.Nitro.components().some((c) => c.name === N('pages::student-page')));

    const counter = byName(document, 'pages::counter');
    click(window, counter.querySelectorAll('button')[1]);
    await until('the new page is live', () => text(counter.querySelector('.count')) === '1');

    window.history.back();
    await until('back button', () => window.location.pathname === '/students/1' && byName(document, 'pages::student-page') !== null);

    /** The visit fetches the page's data, not its HTML: the browser renders it. */
    const answers = [];
    const pageFetch = window.fetch;
    window.fetch = async (...args) => {
        const response = await pageFetch(...args);
        answers.push(response.headers.get('Content-Type'));
        return response;
    };
    click(window, document.querySelector('a.board-link'));
    check('the board\'s chunk loads while the visit fetches its data', chunk('board') !== null && answers.length === 0);
    check('the progress bar shows while the visit loads', document.querySelector('#nprogress .bar') !== null);
    check('with its configured spinner', document.querySelector('#nprogress .spinner') !== null);
    await until('navigated to the board', () => window.location.pathname === '/board' && document.querySelector('[nitro\\:name="todo-list"] li.todo') !== null);
    await until('the progress bar completes and goes', () => document.querySelector('#nprogress') === null);
    check('the bar has the configured colour and height', [...document.querySelectorAll('style')].some((s) => /#nprogress \{[^}]*height: 4px;/.test(s.textContent) && /#nprogress \.bar \{ background: #e11d48;/.test(s.textContent)));    window.fetch = pageFetch;
    check('the visit got JSON', answers.length === 1 && /application\/json/.test(answers[0]), answers.join(', '));
    check('board title followed', document.title === 'Board', document.title);

    const board = document.querySelector('[nitro\\:page]');
    check('the page element is the board', board.getAttribute('nitro:name') === N('pages::board') && text(board.querySelector('h2')) === 'Board');
    check('the page element names its layout', board.getAttribute('nitro:page') === 'components.layouts.app');
    const list = byName(board, 'todo-list');
    check('the child with mount() came from the server', list !== null && window.Nitro.find(list.getAttribute('nitro:id'))?.snapshot !== null);
    check('its own children were made in the browser', list.querySelectorAll('li.todo').length === 2, String(list.querySelectorAll('li.todo').length));
    check('no placeholders left', board.querySelector('[nitro\\:placeholder]') === null);
    check('the children made in the browser loaded their chunk', chunk('todos') !== null && window.Nitro.definitions.has(N('todo-item')));

    click(window, [...list.querySelectorAll('nav button')].find((b) => text(b) === 'Done'));
    await until('the server-made child is live in the browser', () => list.querySelectorAll('li.todo').length === 1);
    const calls = [];
    window.fetch = async (...args) => {
        const response = await pageFetch(...args);
        calls.push(response.status);
        return response;
    };
    click(window, [...list.querySelectorAll('button')].find((b) => text(b) === 'Save all'));
    await until('its #[Server] call answered', () => calls.length === 1);
    window.fetch = pageFetch;
    check('and its signed state was accepted', calls[0] === 200 && list.querySelector('.error') === null, String(calls[0]));

    window.history.back();
    await until('back from the board', () => window.location.pathname === '/students/1' && byName(document, 'pages::student-page') !== null);
    window.history.forward();
    await until('forward to the board', () => window.location.pathname === '/board' && byName(document, 'todo-list') !== null);
    check('only the board\'s components are alive', window.Nitro.components().every((c) => ['pages::board', 'todo-list', 'todo-item'].map(N).includes(c.name)), window.Nitro.components().map((c) => c.name).join(','));

    /** nitro:navigate.keypress: the visit starts as the button goes down; its click is the same visit. */
    const pointer = (type, el, pointerType) => {
        const event = new window.MouseEvent(type, { bubbles: true, cancelable: true, button: 0 });
        Object.defineProperty(event, 'pointerType', { value: pointerType }); // jsdom has no PointerEvent
        el.dispatchEvent(event);
    };
    let visits = 0;
    window.addEventListener('nitro:navigating', () => visits++);
    const historyBefore = window.history.length;
    const pressLink = document.querySelector('a.press-link');

    pointer('pointerdown', pressLink, 'touch');
    check('a touch press waits for the tap', visits === 0, String(visits));
    pointer('pointerdown', pressLink, 'mouse');
    check('a mouse press starts the visit before the button comes up', visits === 1, String(visits));
    pointer('pointerup', pressLink, 'mouse');
    click(window, pressLink);
    await until('the pressed visit lands', () => window.location.pathname === '/counter' && byName(document, 'pages::counter') !== null);
    await wait(20);
    check('the click after the press did not visit again', visits === 1, String(visits));
    check('one history entry for it', window.history.length === historyBefore + 1, `${window.history.length} vs ${historyBefore + 1}`);

    /** A click with no press before it (the keyboard's Enter) still navigates. */
    click(window, document.querySelector('a.board-link'));
    await until('back on the board', () => window.location.pathname === '/board');
    await wait(20);
    click(window, pressLink);
    await until('a keypress link still follows a plain click', () => window.location.pathname === '/counter');
    check('each visit once', visits === 3, String(visits));

    /**
     * A slow visit right after a fast one, while the fast one's bar still fades: it gets a bar of
     * its own while it loads. (NProgress would hand it the fading bar and then remove it, leaving
     * the slow visit barless and a lone first step to show up after it landed.)
     */
    click(window, document.querySelector('a.board-link'));
    await until('a fast visit', () => window.location.pathname === '/board' && byName(document, 'pages::board') !== null);
    const fastFetch = window.fetch;
    window.fetch = async (...args) => {
        await wait(1200);
        return fastFetch(...args);
    };
    click(window, document.querySelector('a[href="/counter"]'));
    await wait(1000);
    const barWhileLoading = document.querySelector('#nprogress .bar');
    check('the slow visit shows its own bar while it loads', barWhileLoading !== null && barWhileLoading.isConnected);
    await until('the slow visit lands', () => window.location.pathname === '/counter' && byName(document, 'pages::counter') !== null, 3000);
    window.fetch = fastFetch;
    await until('and its bar completes and goes', () => document.querySelector('#nprogress') === null, 3000);

    window.close();
});

// ------------------------------------------------------------------ nitro:poll, nitro:init, nitro:confirm, #[Computed]
await scenario('clock', async () => {
    const window = await open('/clock');
    const { document } = window;
    const clock = byName(document, 'clock');
    const $ = (selector) => clock.querySelector(selector);
    const ticks = () => parseInt(text($('.label')), 10);

    await until('nitro:init ran once the element appeared', () => $('.ready') !== null);
    await until('nitro:poll calls a browser method', () => ticks() >= 3);
    check('#[Computed] read twice in a render gives the same value', text($('.label')) === text($('.again')), `${text($('.label'))} / ${text($('.again'))}`);
    await until('nitro:poll calls a #[Server] method too', () => Number(text($('.synced'))) >= 2);

    click(window, $('.stop'));
    await wait(150);
    const stopped = ticks();
    await wait(350);
    check('a poll stops when its element leaves', ticks() === stopped, `${stopped} then ${ticks()}`);

    /** #[Computed]: worked out once until the state changes. */
    const component = window.Nitro.find(clock.getAttribute('nitro:id'));
    const label = component.ctx[M('label')];
    let calls = 0;
    component.ctx[M('label')] = function () {
        calls++;
        return label.call(this);
    };
    click(window, $('.shout-it'));
    await until('a browser method read the computed value', () => text($('.shout')) === `${stopped} TICKS`, 1000);
    /** The method reused the last render's value (nothing had changed); the render after its change worked it out once for both reads. */
    check('computed once per change, not per read', calls === 1, String(calls));

    /** nitro:confirm asks first; a no leaves the state alone. */
    const asked = [];
    window.confirm = (message) => (asked.push(message), false);
    window.prompt = (message) => (asked.push(message), 'nope');
    click(window, $('.reset'));
    click(window, $('.wipe'));
    await wait(50);
    check('declined confirm and wrong prompt: nothing ran', ticks() === stopped, String(ticks()));
    check('the messages', asked[0] === 'Reset the clock?' && asked[1] === 'Type WIPE to confirm', JSON.stringify(asked));

    window.prompt = () => 'WIPE';
    click(window, $('.wipe'));
    await until('the right word runs it', () => ticks() === 0);
    click(window, $('.tick'));
    await until('a plain handler runs without asking', () => ticks() === 1);
    window.confirm = () => true;
    click(window, $('.reset'));
    await until('a confirmed handler runs', () => ticks() === 0);

    /** WithSorting, WithPagination and updated($property), as a Livewire table is written. */
    const roster = byName(document, 'roster');
    const r = (selector) => roster.querySelector(selector);
    const names = () => [...roster.querySelectorAll('li')].map(text).join(',');
    check('rows keep their order until a column is sorted', names() === 'Eva,Bea,Gus' && text(r('.where')) === '1-3 of 7, page 1 of 3', `${names()} ${text(r('.where'))}`);

    click(window, r('.by-name'));
    await until('sorted by name', () => names() === 'Ada,Bea,Cal');
    check('with its icon', text(r('.by-name')) === 'Name ▲', text(r('.by-name')));
    click(window, r('.by-name'));
    await until('the other way round', () => names() === 'Gus,Fay,Eva' && text(r('.by-name')) === 'Name ▼');

    click(window, r('.prev'));
    await wait(30);
    check('no page before the first', text(r('.where')) === '1-3 of 7, page 1 of 3', text(r('.where')));
    const historyLength = window.history.length;
    click(window, r('.next'));
    await until('next page', () => names() === 'Dan,Cal,Bea' && text(r('.where')) === '4-6 of 7, page 2 of 3');
    check('#[Url]: the page is in the URL', window.location.search === '?page=2', window.location.search);
    click(window, r('.third'));
    await until('gotoPage(3)', () => names() === 'Ada' && text(r('.where')) === '7-7 of 7, page 3 of 3');
    check('the URL follows it, in place (no history entry)', window.location.search === '?page=3' && window.history.length === historyLength, `${window.location.search} ${window.history.length}`);

    /** From page 3, a filter leaving 6 rows: updated() resets to page 1 (clamping alone would show page 2). */
    click(window, r('.only-a'));
    await until('updated($property) reset the page', () => names() === 'Fay,Eva,Dan' && text(r('.where')) === '1-3 of 6, page 1 of 2', 1000);
    check('after the filter', names() === 'Fay,Eva,Dan', `${names()} ${text(r('.where'))}`);
    check('#[Url(as: q)] in, the first page out', window.location.search === '?q=a', window.location.search);

    window.close();
});

// ------------------------------------------------------------------ Laravel's helpers in the browser
await scenario('helpers', async () => {
    const window = await open('/links');
    const { document } = window;
    const links = byName(document, 'pages::links');
    const href = (cls) => links.querySelector(`a.${cls}`).getAttribute('href');

    check('the page ships what the helpers name', window.Nitro.B.globals.routes['students.show']?.uri === 'students/{student}' && window.Nitro.B.globals.config['app.name'] !== undefined);
    const root = window.location.origin;
    check('hydrated as the server rendered it', href('next') === root + '/students/2?from=ada%20lovelace', href('next'));

    click(window, links.querySelector('button'));
    await until('route() in a browser method follows the state', () => href('next') === root + '/students/3?from=grace%20hopper');
    check('route() in the template too', href('named') === root + '/students/2?tab=notes', href('named'));
    check('__() with its replacements', text(links.querySelector('a.named')) === "Open Grace hopper's record" && text(links.querySelector('.welcome')) === 'Welcome, GRACE HOPPER! (grace hopper, Grace hopper)', text(links.querySelector('.welcome')));
    check('the other helpers', links.querySelector('img').getAttribute('src') === root + '/img/logo.png' && links.querySelector('img').getAttribute('alt') === 'Nitro Tests' && href('relative') === '/counter?page=2');

    window.close();
});

// ------------------------------------------------------------------ lifecycle hooks, $this->js(), nitro:ref
await scenario('lifecycle', async () => {
    const window = await open('/journal');
    const { document } = window;
    const journal = byName(document, 'pages::journal');
    const note = journal.querySelector('input');

    /** @teleport: the server rendered the content at its @portal (Viewi's portals); the browser hydrated it there. */
    const docks = () => [...document.querySelectorAll('.journal-dock')];
    check('@teleport content is at its @portal, once', docks().length === 1 && docks()[0].parentElement.id === 'dock' && docks()[0].nitroOwner !== undefined && text(docks()[0].querySelector('.dock-note')) === 'first entry', document.body.innerHTML);
    check('nothing of it in place', journal.querySelector('aside, template') === null, journal.innerHTML);
    check('the portal marks and the page around them kept', document.getElementById('portal_dock_end') !== null && document.getElementById('portal_toasts_end') !== null && document.querySelector('nav') !== null, document.body.innerHTML);

    await until('mounted() ran in the browser', () => text(journal.querySelector('.status')) === 'ready, 0 saved');
    await until('its $this->js() used $refs', () => document.activeElement === note);
    await until('a @teleport in an @if came with it', () => document.querySelector('#toasts .journal-toast') !== null);

    const toastNote = document.querySelector('#toasts .toast-note');
    type(window, toastNote, 'from the toast');
    await until('nitro:model in teleported content sets the component\'s state', () => note.value === 'from the toast' && text(docks()[0].querySelector('.dock-note')) === 'from the toast');

    type(window, note, 'hello');
    await until('and shows it', () => toastNote.value === 'hello');
    click(window, journal.querySelector('button'));
    await until('$this->js() from a #[Server] method ran after the render', () => window.savedJournal !== undefined);
    check('with $nitro, $refs and $el', window.savedJournal === 'hello / ready, 1 saved / SECTION', window.savedJournal);
    await until('teleported content renders its @foreach', () => text(docks()[0].querySelector('em')) === 'hello');

    click(window, document.querySelector('#toasts .dismiss'));
    await until('nitro:click in teleported content runs on its component; the @if took the content away', () => text(journal.querySelector('.status')) === 'waiting, 1 saved' && document.querySelector('#toasts .journal-toast') === null);

    let gone = null;
    window.addEventListener('journal-gone', (e) => (gone = e.detail.params));
    click(window, journal.querySelector('a.away'));
    await until('navigated away', () => window.location.pathname === '/counter');
    await until('destroyed() ran as it left', () => gone !== null);
    check('with its last state', JSON.stringify(gone) === '["hello"]', JSON.stringify(gone));
    check('its teleported content left with it', document.querySelector('.journal-dock') === null, document.body.innerHTML);

    window.close();
});

// ------------------------------------------------------------------ every hook (Support/ComponentHooks.php, PropertyHooks.php)
await scenario('hooks', async () => {
    const window = await open('/lifecycle');
    const { document } = window;
    const page = byName(document, 'pages::lifecycle');
    const $ = (selector) => page.querySelector(selector);
    const lines = () => [...page.querySelectorAll('.log li')].map(text);

    type(window, $('.title'), 'final');
    await until('updating, then updated: the own hook, then the one for every property', () => lines().length === 3);
    check('updatingTitle() saw the old value, updatedTitle() the new', JSON.stringify(lines()) === JSON.stringify(['updating title: draft -> final', `updating ${P_('title')}`, 'updated title: final']), JSON.stringify(lines()));

    await until('rendered() ran after the render', () => text($('.renders')) === '1');
    await wait(150);
    check('and not again for what it changed itself: no loop', text($('.renders')) === '1', text($('.renders')));

    type(window, $('.email'), 'a@b.c');
    /** updating($property): the path as the browser names it (an obfuscated build's alias), as updated() has it. */
    await until('a change deep inside: the key under the property', () => lines().includes('updated form.email = a@b.c') && lines().includes(`updating ${P_('form')}.email`));
    check('in order', lines().join('|').endsWith(`updating ${P_('form')}.email|updated form.email = a@b.c`), JSON.stringify(lines()));

    type(window, $('.first-name'), 'Ada');
    await until('updatedFirstName() is $first_name\'s', () => lines().includes('first_name = Ada'));

    type(window, $('.code'), 'abc');
    await until('#[Server] updatedCode(): it ran on the server, with the change', () => text($('.checked')) === 'ABC on the server', 5000);
    check('updatedCode detail', text($('.checked')) === 'ABC on the server', JSON.stringify({ checked: text($('.checked')), code: $('.code').value, log: lines() }));

    click(window, $('.explode'));
    await until('exception(): the #[Server] method threw, the hook handled it', () => text($('.failure')) === 'handled: it broke');

    click(window, $('.drop'));
    await until('the child left', () => page.querySelector('.lifecycle-child') === null);
    await wait(300);
    click(window, $('.count'));
    await until('#[Server] destroyed() ran on the server as it left', () => text($('.gone')) === '1', 5000);
    check('destroyed detail', text($('.gone')) === '1', text($('.gone')));

    window.close();
});

// ------------------------------------------------------------------ slots
await scenario('slots', async () => {
    const window = await open('/shelf');
    const { document } = window;
    const [stock, empty] = document.querySelectorAll('[nitro\\:name="card"]');
    const count = () => text(stock.querySelector('.count'));
    const items = () => [...stock.querySelectorAll('.body > ul > li')].map(text).join(',');

    check('hydrated: the parent\'s content in the card', count() === 'Books: 1' && items() === 'Atlas,Bible', `${count()} ${items()}`);
    check('the empty card: its fallback, no footer', !empty.querySelector('footer') && text(empty.querySelector('.blank')) === 'Nothing here yet.' && !stock.querySelector('.blank'));
    check('the child in the slot is live', window.Nitro.find(stock.querySelector('li.todo')?.getAttribute('nitro:id') ?? '') !== null);

    click(window, stock.querySelector('footer .add'));
    await until('the parent\'s handler in a named slot, and its content in the card followed', () => count() === 'Books: 2' && items() === 'Atlas,Bible,Item 2');

    type(window, stock.querySelector('input.label'), 'Maps');
    await until('the parent\'s field in the card', () => count() === 'Maps: 2');
    await until('the slot\'s own child took the parent\'s value', () => text(stock.querySelector('li.todo span')) === 'Maps');
    check('the parent\'s state, not the card\'s', window.Nitro.components().find((c) => c.name === N('pages::shelf')).data.get(P_('label')) === 'Maps');

    click(window, stock.querySelector('.toggle'));
    await until('the card hid its body (its own state)', () => stock.querySelector('.body') === null);
    click(window, stock.querySelector('.toggle'));
    await until('and showed it again', () => stock.querySelector('.body') !== null);
    check('rendered from the parent, as it is now', count() === 'Maps: 2' && items() === 'Atlas,Bible,Item 2', `${count()} ${items()}`);
    click(window, stock.querySelector('footer .add'));
    await until('and still the parent\'s, live', () => count() === 'Maps: 3' && items() === 'Atlas,Bible,Item 2,Item 3');

    window.close();
});

// ------------------------------------------------------------------ events and nitro:model on a child (Livewire's ways)
await scenario('events', async () => {
    const window = await open('/shelf');
    const { document } = window;
    const shelf = byName(document, 'pages::shelf');
    const box = byName(shelf, 'search-box');
    const input = box.querySelector('input.search');
    const notes = () => [...shelf.querySelectorAll('.notes li')].map(text).join(',');
    const heard = () => [...shelf.querySelectorAll('.card .heard')].map(text).join(',');
    let windowHeard = 0;
    window.addEventListener('noted', () => windowHeard++);

    check('#[Modelable]: the child starts with the parent\'s value', input.value === 'maps', input.value);
    type(window, input, 'atlas');
    await until('the child\'s field writes the parent\'s property', () => text(shelf.querySelector('.query')) === 'atlas');
    click(window, shelf.querySelector('.reset-query'));
    await until('the parent\'s change reaches the child', () => input.value === 'reset' && window.Nitro.find(box.getAttribute('nitro:id')).data.get(P_('value')) === 'reset');
    click(window, box.querySelector('.clear'));
    await until('a child method changing it: the parent follows', () => text(shelf.querySelector('.query')) === '');

    const count = text(shelf.querySelector('.count'));
    click(window, box.querySelector('.parent-add'));
    await until('$parent->add() in the child ran the parent\'s method', () => text(shelf.querySelector('.count')) !== count);

    type(window, input, 'globe');
    await wait(20);
    click(window, box.querySelector('.announce'));
    await until('dispatch()->to(Shelf::class): the shelf heard it', () => notes() === 'globe');
    click(window, box.querySelector('.ping'));
    await until('->self(): the box heard itself', () => text(box.querySelector('.pings')) === '1');
    click(window, shelf.querySelector('.dispatch-to'));
    await until('$dispatchTo in a handler', () => notes() === 'globe,via action');
    click(window, box.querySelector('.server'));
    await until('->to() from a #[Server] method', () => notes() === 'globe,via action,from the server');
    await wait(30);
    check('the cards listen too, but were not sent any', heard() === '0,0', heard());
    check('a targeted dispatch does not go to the window', windowHeard === 0, String(windowHeard));

    window.close();
});

// ------------------------------------------------------------------ dynamic components
await scenario('dynamic components', async () => {
    const window = await open('/panels');
    const { document } = window;
    const panels = byName(document, 'pages::panels');
    const current = () => panels.querySelector('.current [nitro\:name]');
    const ids = () => window.Nitro.components().map((c) => c.id);

    check('<nitro:dynamic-component> renders the component :is names', current()?.getAttribute('nitro:name') === N('card'), panels.innerHTML);
    check('in a loop, each its own', [...panels.querySelectorAll('li > [nitro\:name]')].map((el) => el.getAttribute('nitro:name')).join(',') === ['card', 'search-box'].map(N).join(','), panels.innerHTML);
    const card = current();
    const cardId = card.getAttribute('nitro:id');
    check('keyed with its name', cardId.endsWith('.c0:card'), cardId);

    click(window, card.querySelector('.toggle'));
    await until('the card has state', () => text(current().querySelector('.toggle')) === 'Show');

    click(window, panels.querySelector('.to-search'));
    await until('another name renders that component instead', () => current()?.getAttribute('nitro:name') === N('search-box'));
    check('the one before left the page, and the runtime', !card.isConnected && !ids().includes(cardId), ids().join(' '));

    click(window, panels.querySelector('.to-none'));
    await until('"" renders none', () => current() === null && panels.querySelector('.current').children.length === 0);

    click(window, panels.querySelector('.to-card'));
    await until('back to the card: a new one', () => current()?.getAttribute('nitro:name') === N('card'));
    check('with its own state (not the old one\'s)', text(current().querySelector('.toggle')) === 'Hide', current().outerHTML);

    window.close();
});

await scenario('stores', async () => {
    const window = await open('/shop');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);
    const badge = () => text($('.cart-badge'));

    check('the store as the server made it (its mount())', badge() === '0 · 0 · server', badge());
    click(window, document.querySelectorAll('.shop-shelf .buy')[0]);
    click(window, document.querySelectorAll('.shop-shelf .buy')[1]);
    await until('a component changes the store; another renders it again', () => badge() === '2 · 12 · server');
    check('a component\'s #[Computed] value from the store is worked out again', text($('.in-cart')) === '2', text($('.in-cart')));

    click(window, $('.buy-direct'));
    await until('a store method called from a view', () => badge() === '3 · 17 · server');
    check('in the browser: no request', !window.Nitro.stores.get(N('stores::cart'))?.snapshot?.state?.includes('Gift'));

    const store = window.Nitro.stores.get(N('stores::cart'));
    click(window, $('.to-cart'));
    await until('a visit to another page', () => window.location.pathname === '/shop/cart' && $('.cart-list') !== null);
    check('the same store, kept across the visit', window.Nitro.stores.get(N('stores::cart')) === store);
    await until('a component made after the changes reads them', () => [...document.querySelectorAll('.cart-list .item')].map((li) => text(li).replace(/\s*x$/, '')).join(',') === 'Pen,Book,Gift');
    check('and the new page\'s badge', badge() === '3 · 17 · server', badge());

    click(window, $('.cart-list .remove'));
    await until('removed through the store, from the list\'s view: the badge follows', () => badge() === '2 · 15 · server' && document.querySelectorAll('.cart-list .item').length === 2);

    click(window, $('.cart-list .save'));
    await until('the store\'s #[Server] method, with the state as the browser had it', async () => true);
    await wait(400);
    window.close();

    const again = await open('/shop/cart');
    check('saved on the server: a new page load has it (its mount() reads the session)', text(again.document.querySelector('.cart-badge')) === '2 · 15 · server', text(again.document.querySelector('.cart-badge')));

    click(again, again.document.querySelector('.to-shop'));
    await until('back to the shop', () => again.location.pathname === '/shop' && again.document.querySelector('.shop-shelf') !== null);
    click(again, again.document.querySelector('.checkout'));
    await until('a component\'s #[Server] method reads the store and changes it: the browser gets it back', () => text(again.document.querySelector('.cart-badge')) === '3 · 15 · server');
    again.close();
});

/** Nitro's own plugins, on the cart store: #[Persist] (localStorage, shared by the tabs) and #[Sync] (BroadcastChannel). */
await scenario('store plugins: #[Persist] and #[Sync]', async () => {
    const storage = new SharedStorage();
    const key = `nitro:persist:${N('stores::cart')}`;
    const badgeOf = (w) => text(w.document.querySelector('.cart-badge'));
    const countOf = (w) => Number(badgeOf(w).split(' · ')[0]);

    const first = await open('/shop', { localStorage: storage, tabs: true });
    const definition = first.Nitro.stores.get(N('stores::cart')).definition;
    check('kept and shared: items only (madeBy is #[Locked], the server\'s)', definition.persist.length === 1 && JSON.stringify(definition.sync) === JSON.stringify(definition.persist), JSON.stringify([definition.persist, definition.sync]));
    const items = definition.persist[0];
    const start = countOf(first);

    click(first, first.document.querySelector('.buy-direct'));
    await until('#[Persist]: a change is kept in localStorage', () => countOf(first) === start + 1 && (storage.getItem(key) ?? '').includes('Gift'));

    /** A new tab: the server's page (the session's cart: the Gift was not saved), then the items kept. */
    const second = await open('/shop', { localStorage: storage, tabs: true });
    await until('#[Persist]: a new page load starts from the items kept', () => countOf(second) === start + 1 && badgeOf(second) === badgeOf(first));

    click(first, first.document.querySelector('.buy-direct'));
    await until('#[Sync]: a change in one tab follows in the other', () => countOf(first) === start + 2 && countOf(second) === start + 2);
    click(second, second.document.querySelectorAll('.shop-shelf .buy')[0]);
    await until('#[Sync]: and back', () => countOf(first) === start + 3 && countOf(second) === start + 3);
    await wait(100);
    check('no echo between the tabs: each change once', countOf(first) === start + 3 && countOf(second) === start + 3, `${badgeOf(first)} / ${badgeOf(second)}`);
    check('madeBy (#[Locked]) stays the server\'s in each', badgeOf(first).endsWith('· server') && badgeOf(second).endsWith('· server'), `${badgeOf(first)} / ${badgeOf(second)}`);

    /** The plugin API's store, used after the page started: the stores already made, and set() refused on a #[Locked] property. */
    let seen = null;
    first.Nitro.use(({ on }) => on('store', (store) => {
        seen = store;
    }));
    check('a store listener registered late hears the stores already made', seen?.name === N('stores::cart'), seen?.name);
    let refused = '';
    try {
        seen.set(P_('madeBy'), 'browser');
    } catch (error) {
        refused = error.message;
    }
    check('set() on a #[Locked] property is refused', refused.includes('#[Locked]') && badgeOf(first).endsWith('· server'), refused);
    first.close();
    second.close();
    for (const channel of channels.splice(0)) channel.close();

    /** What localStorage holds is the browser's: set as its own change, then checked by the server like any. */
    storage.setItem(key, JSON.stringify({ [items]: [{ $m: [['name', 'Forged'], ['price', -100]] }] }));
    const third = await open('/shop', { localStorage: storage });
    await until('a kept value is set as the browser\'s own change', () => badgeOf(third) === '1 · -100 · server');
    click(third, third.document.querySelector('.checkout'));
    await until('the server checks it (items.*.price min:0): checkout() does not run', () => third.Nitro.stores.get(N('stores::cart')).errors.has(`${P_('items')}.0.price`));
    check('the cart is as it was', badgeOf(third) === '1 · -100 · server', badgeOf(third));
    third.close();
});

/** Nitro.use(): a plugin's hooks, registered after the page started. */
await scenario('plugins', async () => {
    const window = await open('/counter');
    const { document } = window;
    const counter = document.querySelector('.counter');
    const heard = [];
    const sent = [];
    const fetched = window.fetch;
    window.fetch = (input, init = {}) => {
        sent.push(init.headers ?? {});
        return fetched(input, init);
    };
    const errors = [];
    window.addEventListener('nitro:error', (event) => errors.push(event.detail.message));
    /** The listener below fails on purpose: reported, as a plugin's error is. */
    expectedErrors.push(/a listener that fails/);

    const plugin = ({ on }) => {
        on('component', (component) => heard.push(`component ${component.name}`));
        on('render', (component) => heard.push(`render ${component.name}`));
        on('request', ({ method, headers }) => {
            headers['X-Plugin'] = 'yes';
            headers['X-CSRF-TOKEN'] = 'forged';
            heard.push(`request ${method}`);
        });
        on('request', () => {
            throw new Error('a listener that fails');
        });
        on('response', ({ method, status }) => heard.push(`response ${method} ${status}`));
    };
    window.Nitro.use(plugin);
    window.Nitro.use(plugin);
    check('registered late: it hears the components already started, once though used twice', heard.filter((h) => h.startsWith('component ')).length === window.Nitro.components().length && heard.length === window.Nitro.components().length, heard.join(', '));

    heard.length = 0;
    click(window, counter.querySelector('button:nth-of-type(2)'));
    await until('render: after a render', () => heard.some((h) => h.startsWith('render ')));

    heard.length = 0;
    click(window, [...counter.querySelectorAll('button')].find((b) => text(b) === 'Save'));
    /** By the browser's name for the method (an obfuscated build's alias). */
    await until('request and response around a #[Server] call', () => heard.includes(`request ${M('save')}`) && heard.includes(`response ${M('save')} 200`));
    const headers = sent.at(-1) ?? {};
    check('a plugin adds a header', headers['X-Plugin'] === 'yes', JSON.stringify(headers));
    check('but cannot change Nitro\'s own', headers['X-CSRF-TOKEN'] !== 'forged' && headers['X-CSRF-TOKEN'] === window.document.querySelector('script[data-csrf]').dataset.csrf, headers['X-CSRF-TOKEN']);
    check('a listener that throws is reported, the others still run', errors.includes('a listener that fails') && heard.includes(`response ${M('save')} 200`), errors.join(', '));

    const refused = (fn) => {
        try {
            fn();
            return '';
        } catch (error) {
            return error.message;
        }
    };
    check('a plugin is a function', refused(() => window.Nitro.use({})) === 'Nitro.use(): a plugin is a function, called with the plugin API');
    check('only its hooks', refused(() => window.Nitro.use(({ on }) => on('mount', () => {}))).startsWith('Nitro.use(): on(): there is no hook "mount"'));
    window.close();
});

await scenario('server calls from children', async () => {
    const window = await open('/counter');
    const { document } = window;
    const link = document.createElement('a');
    link.href = '/panels';
    link.setAttribute('nitro:navigate', '');
    document.body.appendChild(link);
    click(window, link);
    await until('a data visit to the page', () => window.location.pathname === '/panels' && byName(document, 'pages::panels') !== null);
    const stamps = () => [...document.querySelectorAll('.stamps .stamp')];

    click(window, stamps()[0].querySelector('.do'));
    await until('a child without mount() the server made for the visit calls the server', () => text(stamps()[0].querySelector('.by')) === 'server:a:', 3000);

    click(window, document.querySelector('.add-row'));
    await until('a row added in the browser', () => stamps().length === 2);
    type(window, stamps()[1].querySelector('.note'), 'typed');
    click(window, stamps()[1].querySelector('.do'));
    await until('a child made in the browser takes its state from the server, then calls it with what was typed', () => text(stamps()[1].querySelector('.by')) === 'server:r1:typed', 3000);
    check('the first row keeps its own', text(stamps()[0].querySelector('.by')) === 'server:a:', text(stamps()[0]));

    click(window, document.querySelector('.add-badge'));
    await until('a #[Server] call whose state places a child with mount(): the server creates it with the answer', () => text(document.querySelector('.badges .badge')) === 'BADGE 1', 3000);
    click(window, document.querySelector('.add-badge'));
    await until('and the next', () => [...document.querySelectorAll('.badges .badge')].map(text).join(',') === 'BADGE 1,BADGE 2', 3000);
    check('live components, not HTML', window.Nitro.components().filter((c) => c.name === N('badge')).length === 2);

    const keys = document.querySelector('.keys');
    keys.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    await until('the first of two nitro:keydown handlers on one element', () => text(document.querySelector('.key')) === 'enter');
    keys.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await until('and the second', () => text(document.querySelector('.key')) === 'escape');

    /** .ctrl, .shift: held during the event, a key's or a click's; without them, nothing. */
    const ctrlKeys = document.querySelector('.ctrl-keys');
    ctrlKeys.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    document.querySelector('.shift-click').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 100));
    check('Enter without Ctrl, a click without Shift: neither handler runs', text(document.querySelector('.key')) === 'escape', text(document.querySelector('.key')));
    ctrlKeys.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true }));
    await until('nitro:keydown.ctrl.enter: Ctrl+Enter', () => text(document.querySelector('.key')) === 'ctrl-enter');
    document.querySelector('.shift-click').dispatchEvent(new window.MouseEvent('click', { shiftKey: true, bubbles: true }));
    await until('nitro:click.shift: a click with Shift held', () => text(document.querySelector('.key')) === 'shift-click');

    /** A key written as its letter or digit: either case, and a digit by its key on the keyboard. */
    const letters = document.querySelector('.letter-keys');
    letters.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'j', code: 'KeyJ', ctrlKey: true, bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 100));
    check('Ctrl+J is not .ctrl.k', text(document.querySelector('.key')) === 'shift-click', text(document.querySelector('.key')));
    letters.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'k', code: 'KeyK', ctrlKey: true, bubbles: true }));
    await until('nitro:keydown.ctrl.k: Ctrl+K', () => text(document.querySelector('.key')) === 'ctrl-k');
    letters.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'K', code: 'KeyK', shiftKey: true, bubbles: true }));
    await until('nitro:keydown.shift.k: Shift+K types "K", still .k', () => text(document.querySelector('.key')) === 'shift-k');
    letters.dispatchEvent(new window.KeyboardEvent('keydown', { key: '@', code: 'Digit2', shiftKey: true, bubbles: true }));
    await until('nitro:keydown.2: the 2 key, though Shift makes it "@"', () => text(document.querySelector('.key')) === 'two');

    window.close();
});

// ------------------------------------------------------------------ Livewire and Nitro on one page
await scenario('livewire', async () => {
    const window = await open('/both');
    const { document } = window;
    await until('Livewire started', () => window.Livewire !== undefined && document.querySelector('.ticker') !== null);

    const counter = byName(document, 'counter');
    click(window, counter.querySelectorAll('button')[1]);
    await until('the Nitro component works next to Livewire', () => text(counter.querySelector('.count')) === '1');

    click(window, document.querySelector('.ticker button'));
    await until('the Livewire component works next to Nitro', () => text(document.querySelector('.ticks')) === '1', 5000);

    check('Nitro kept its state through Livewire\'s update', text(counter.querySelector('.count')) === '1');
    check('Livewire did not pick up Nitro\'s component', !counter.hasAttribute('wire:id'));
    window.close();
});

// ------------------------------------------------------------------ uploads (/gallery)
await scenario('uploads', async () => {
    const window = await open('/gallery');
    const { document } = window;
    const gallery = document.querySelector('.gallery');
    const input = gallery.querySelector('input.photo');

    /** A file chosen in the input (a browser's FileList; jsdom's inputs take none, so a list stands in). */
    const choose = (el, files) => {
        Object.defineProperty(el, 'files', { configurable: true, value: files });
        change(window, el);
    };
    const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='), (c) => c.charCodeAt(0));

    const events = [];
    for (const name of ['nitro-upload-start', 'nitro-upload-progress', 'nitro-upload-finish', 'nitro-upload-error']) {
        input.addEventListener(name, (e) => events.push(name + (e.detail?.status ? `:${e.detail.status}` : '')));
    }

    choose(input, [new window.File([png], 'Birthday Cake.png', { type: 'image/png' })]);
    check('the loading state shows while it uploads', gallery.querySelector('.uploading').hasAttribute('data-nitro-loading'));
    await until('the preview shows', () => /\/_nitro\/upload\/preview\?t=/.test(gallery.querySelector('.preview img')?.getAttribute('src') ?? ''));
    check('what the view shows of it', text(gallery.querySelector('figcaption')) === 'Birthday Cake.png (png, image/png, 68 bytes)', text(gallery.querySelector('figcaption')));
    check('start and finish on the input', events.includes('nitro-upload-start') && events.includes('nitro-upload-finish'), events.join(', '));
    check('the loading state is gone', !gallery.querySelector('.uploading').hasAttribute('data-nitro-loading'));

    /** As the <img> loads it: with the page's cookies (the preview's reference is good in its session only). */
    const preview = await browserFetch(window)(new URL(gallery.querySelector('.preview img').getAttribute('src'), url));
    check('the preview is the image', preview.status === 200 && preview.headers.get('content-type') === 'image/png', `${preview.status} ${preview.headers.get('content-type')}`);

    /** Several, for the array property. */
    choose(gallery.querySelector('input.photos'), [new window.File([png], 'one.png', { type: 'image/png' }), new window.File(['hello'], 'notes.txt', { type: 'text/plain' })]);
    await until('the list shows both', () => text(gallery.querySelector('.count')) === '2 photos');
    check('their names', text(gallery.querySelector('ul.photos')) === 'one.png notes.txt', text(gallery.querySelector('ul.photos')));

    click(window, gallery.querySelector('.save'));
    await until('save() stored the photo', () => text(gallery.querySelector('.stored')) === 'gallery/cover.png');
    check('the property is empty again', gallery.querySelector('.preview') === null && text(gallery.querySelector('.count')) === '0 photos');
    check('so is the input', input.value === '');

    /** Too big for nitro.uploads.rules (max 1024 KB): the message, as validate() gives it. */
    choose(input, [new window.File([new Uint8Array(1100 * 1024)], 'huge.png', { type: 'image/png' })]);
    await until('the error shows', () => /must not be greater than 1024 kilobytes/.test(text(gallery.querySelector('.error')) ?? ''));
    check('an error event', events.includes('nitro-upload-error:422'), events.join(', '));
    check('no photo', gallery.querySelector('.preview') === null);

    choose(input, [new window.File([png], 'again.png', { type: 'image/png' })]);
    await until('a good file clears the error', () => gallery.querySelector('.error') === null && text(gallery.querySelector('figcaption'))?.startsWith('again.png'));

    /** A plugin hears an upload as $upload, and its headers go with it, as with a call. */
    const heard = [];
    const setHeaders = [];
    const setRequestHeader = window.XMLHttpRequest.prototype.setRequestHeader;
    window.XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
        setHeaders.push(`${name}: ${value}`);
        return setRequestHeader.call(this, name, value);
    };
    window.Nitro.use(({ on }) => {
        on('request', ({ method, headers }) => {
            if (method === '$upload') headers['X-Plugin'] = 'upload';
            heard.push(`request ${method}`);
        });
        on('response', ({ method, status }) => heard.push(`response ${method} ${status}`));
    });
    choose(input, [new window.File([png], 'plugin.png', { type: 'image/png' })]);
    await until('the plugin hears the upload answered', () => heard.includes('response $upload 200'));
    check('a plugin\'s header goes with an upload', setHeaders.includes('X-Plugin: upload'), setHeaders.join(', '));
    check('and it hears the upload sent', heard.includes('request $upload'), heard.join(', '));

    /** Expired state (another session, a new key): nitro:expired, as for a call; a listener can keep the page. */
    const expired = [];
    window.addEventListener('nitro:expired', (event) => {
        expired.push(event.detail.status);
        event.preventDefault();
    });
    expectedErrors.push(/uploading /);
    const owner = window.Nitro.owner(input);
    owner.snapshot = { ...owner.snapshot, checksum: 'forged' };
    choose(input, [new window.File([png], 'late.png', { type: 'image/png' })]);
    await until('an upload with state that no longer holds is told so', () => expired.includes(419));
    window.close();
});

// ------------------------------------------------------------------ Blade components and @include (/showcase)
await scenario('inlined views', async () => {
    const window = await open('/showcase');
    const { document } = window;
    const showcase = document.querySelector('.showcase');
    const [clickButton, featureButton] = showcase.querySelectorAll('button');

    /** nitro:click given to <x-button>: the Nitro component's method. */
    click(window, clickButton);
    await until('the count follows', () => text(showcase.querySelector('.clicks')) === '1');
    check('the card\'s named slot follows', text(showcase.querySelector('.foot')) === '1 clicks', text(showcase.querySelector('.foot')));

    click(window, clickButton);
    click(window, clickButton);
    await until('three clicks', () => text(showcase.querySelector('.clicks')) === '3');

    /** #[Browser] classes: Money::format() in the view, Basket::summary() (calling Money) in a method. */
    check('a #[Browser] class\'s method in the view (named by @use)', text(showcase.querySelector('.price')) === '£37.50', text(showcase.querySelector('.price')));
    check('@use with an alias', text(showcase.querySelector('.levels')) === '3 levels, High', text(showcase.querySelector('.levels')));
    check('and in a browser method, calling another', text(showcase.querySelector('.basket')) === '£37.50 (free delivery)', text(showcase.querySelector('.basket')));
    check(':disabled given to <x-button> is on (true: the attribute\'s name)', clickButton.getAttribute('disabled') === 'disabled', clickButton.outerHTML);

    /** @includeWhen, a prop and @class inside components follow the state. */
    check('no featured heading yet', !text(showcase).includes('Featured'));
    click(window, featureButton);
    await until('the featured heading shows', () => text(showcase).includes('Featured'));
    check('the badges follow the tone', showcase.querySelector('#main-badge').className === 'badge badge-red extra', showcase.querySelector('#main-badge').className);
    check('the rows see $tone', [...showcase.querySelectorAll('li small')].every((s) => text(s) === 'red'));
    check('the card has its shadow', showcase.querySelector('.card').classList.contains('shadow'));

    click(window, featureButton);
    await until('and back', () => !text(showcase).includes('Featured') && showcase.querySelector('#main-badge').className === 'badge badge-gold extra');
    check('the shadow is gone', !showcase.querySelector('.card').classList.contains('shadow'));
    /** nitro:tooltip's runs so far: the renders below change other things only. */
    const directiveRuns = window.directiveLog.length;

    /** #[Browser] enums: an enum property read as its case, a <select> of cases(), a case's methods, and the server given the case. */
    const select = showcase.querySelector('.status');
    const badge = () => text(showcase.querySelector('.status-badge'));
    check('the options are the cases, labelled', [...select.options].map((o) => `${o.value}:${text(o)}`).join(',') === 'draft:Draft,paid:Paid,shipped:On its way,cancelled:Cancelled', select.innerHTML);
    check('the case\'s methods render', badge() === 'Draft (draft, Draft)', badge());
    check('the select shows the value', select.value === 'draft', select.value);

    click(window, showcase.querySelector('.advance'));
    await until('a browser method assigns a case: $this->status->next()', () => badge() === 'Paid (paid, Paid)' && select.value === 'paid');

    click(window, showcase.querySelector('.confirm'));
    await until('a #[Server] method gets the case', () => text(showcase.querySelector('.seen')) === 'server saw Paid (paid)');
    check('and the property is still the case after the server\'s answer', badge() === 'Paid (paid, Paid)', badge());

    select.value = 'cancelled';
    change(window, select);
    await until('nitro:model writes the value; the view reads the case', () => badge() === 'Cancelled ✓ (cancelled, Cancelled)');
    click(window, showcase.querySelector('.confirm'));
    await until('the server gets the case the select chose', () => text(showcase.querySelector('.seen')) === 'server saw Cancelled');

    /** The application's own nitro: attributes: nitro:tooltip, registered on nitro:starting by a script before the runtime (the layout). */
    const log = window.directiveLog;
    const tip = () => showcase.querySelector('.tip');
    check('a directive runs with the value and modifiers', tip()?.dataset.tip === '3 clicks @top', tip()?.dataset.tip);
    check('it ran for each value, cleaning up before each run again', log[0] === 'run 0 clicks' && log.at(-1) === 'run 3 clicks'
        && log.filter((l) => l.startsWith('run')).length === log.filter((l) => l.startsWith('cleanup')).length + 1, log.join(', '));
    check('and only when the value changed (the other renders left it alone)', log.length === directiveRuns, log.join(', '));

    click(window, featureButton);
    await until('its element goes: the cleanup runs', () => tip() === null && log.at(-1) === 'cleanup 3 clicks');
    click(window, featureButton);
    await until('it comes back: the directive runs on the new element', () => tip()?.dataset.tip === '3 clicks @top' && log.at(-1) === 'run 3 clicks');

    /** Registered after the page started: it runs on the elements already there; component.dispatch() reaches #[On]. */
    check('nothing ran nitro:tell yet', text(showcase.querySelector('.told')) === '');
    window.Nitro.directive('tell', ({ value, component }) => component.dispatch('told', value, { by: 'directive' }));
    await until('a directive registered late runs, and its event reaches the component', () => text(showcase.querySelector('.told')) === 'hello from directive');

    let refused = '';
    try {
        window.Nitro.directive('tooltip', () => {});
    } catch (error) {
        refused = error.message;
    }
    check('a name registers once', refused === "Nitro.directive('tooltip') is registered already", refused);
    window.close();
});

await scenario('render and shared', async () => {
    const window = await open('/greeting');
    const { document } = window;
    const greeting = () => document.querySelector('.greeting');

    /** Nitro::share()'s data, rendered in the browser as on the server. */
    check('the shared name after hydration', text(greeting().querySelector('.hello')) === 'Hello Ada', text(greeting().querySelector('.hello')));
    check('a missing key gives the default', text(greeting().querySelector('.missing')) === 'fallback');

    /** $this->shared() in a browser method. */
    click(window, greeting().querySelector('.shout'));
    await until('the browser method reads the shared data', () => text(greeting().querySelector('.shouted')) === 'ADA');

    /** A #[Server] call that changes the shared data: its answer re-renders with the new data. */
    click(window, greeting().querySelector('.rename'));
    await until('the shared name follows the call', () => text(greeting().querySelector('.hello')) === 'Hello Grace');

    /** $visit(url) from a handler: a nitro:navigate visit to another page. */
    const runtime = window.Nitro;
    click(window, greeting().querySelector('.go'));
    await until('$visit: the page is shown in place', () => window.location.pathname === '/counter' && text(document.querySelector('.count')) === '0');
    check('the greeting is gone', greeting() === null);
    check('the page was not reloaded', window.Nitro === runtime);
    window.close();
});

await scenario('scroll and builds', async () => {
    const window = await open('/greeting');
    const { document } = window;
    /** jsdom does not scroll: the position is set by hand and scrollTo() is recorded. */
    const scrolls = [];
    window.scrollTo = (x, y) => scrolls.push(`${x},${y}`);
    const scrollTo = (y) => {
        Object.defineProperty(window, 'scrollY', { value: y, configurable: true });
        window.dispatchEvent(new window.Event('scroll'));
    };

    scrollTo(420);
    await until('the scroll position is kept in the history entry', () => window.history.state?.nitroScroll?.y === 420);

    click(window, document.querySelector('.to-counter'));
    await until('a visit goes to the top', () => window.location.pathname === '/counter' && scrolls.at(-1) === '0,0');

    window.history.back();
    await until('back restores the position', () => window.location.pathname === '/greeting' && document.querySelector('.greeting') !== null && scrolls.at(-1) === '0,420');

    const before = scrolls.length;
    click(window, document.querySelector('.to-counter-kept'));
    await until('a preserve-scroll visit lands', () => window.location.pathname === '/counter');
    check('nitro:navigate.preserve-scroll keeps the position', scrolls.length === before, scrolls.join(' '));
    window.close();

    /** A browser running another build's components: the server answers 409, the page is not rendered in place. */
    const stale = await open('/greeting');
    const statuses = [];
    const fetch = stale.fetch;
    stale.fetch = async (...args) => {
        const response = await fetch(...args);
        statuses.push(response.status);
        return response;
    };
    stale.Nitro.config.bundle = 'an-earlier-build';
    click(stale, stale.document.querySelector('.to-counter'));
    await until('the visit is answered 409', () => statuses.includes(409));
    await wait(100);
    check('the stale page is not rendered in place', stale.document.querySelector('.greeting') !== null && stale.document.querySelector('.count') === null);
    stale.close();
});

await scenario('deferred', async () => {
    /** A page loaded in full: it shows, then asks for its deferred properties. */
    const window = await open('/report');
    const { document } = window;
    await until('the deferred properties load', () => text(document.querySelector('.stats')) === '12 orders, 340');
    check('the placeholder is gone', document.querySelector('.loading') === null);
    check('@deferred without names waits for all of them', text(document.querySelector('.all')) === 'Chart: 3 points', text(document.querySelector('.all')));
    click(window, document.querySelector('.click'));
    await until('the page works after the load', () => text(document.querySelector('.click')) === '1');
    window.close();

    /** A visit: the placeholder shows until they are here. */
    const visiting = await open('/greeting');
    const fetch = visiting.fetch;
    let release;
    const held = new Promise((resolve) => (release = resolve));
    visiting.fetch = async (input, init = {}) => {
        if (new Headers(init.headers ?? {}).has('X-Nitro-Partial')) await held;
        return fetch(input, init);
    };
    click(visiting, visiting.document.querySelector('.to-report'));
    await until('the page shows with its placeholder', () => text(visiting.document.querySelector('.loading')) === 'Loading stats');
    check('nothing deferred is shown yet', visiting.document.querySelector('.stats') === null && visiting.document.querySelector('.all') === null);
    click(visiting, visiting.document.querySelector('.click'));
    await until('it can be used meanwhile', () => text(visiting.document.querySelector('.click')) === '1');
    release();
    await until('then they load', () => text(visiting.document.querySelector('.stats')) === '12 orders, 340');
    check('what changed meanwhile stays', text(visiting.document.querySelector('.click')) === '1', text(visiting.document.querySelector('.click')));
    visiting.close();
});

await scenario('partial reloads', async () => {
    const window = await open('/orders');
    const { document } = window;
    const list = () => [...document.querySelectorAll('.list li')].map(text).join(' ');
    const total = () => text(document.querySelector('.total'));

    type(window, document.querySelector('.note'), 'hello');
    await until('the note is typed', () => text(document.querySelector('.note-shown')) === 'hello');

    click(window, document.querySelector('.add'));
    await until('$this->reload(\'orders\') after a #[Server] call reloads the orders', () => list() === 'tea');
    check('a property it does not name keeps its value', total() === '0', total());
    check('so does what was typed', text(document.querySelector('.note-shown')) === 'hello');

    click(window, document.querySelector('.reload-total'));
    await until('$reload(\'total\') in a handler', () => total() === '1');

    click(window, document.querySelector('.add'));
    await until('another order', () => list() === 'tea tea');
    check('a reloaded #[Locked] property survives the next #[Server] call', total() === '1', total());
    click(window, document.querySelector('.refresh-total'));
    await until('$this->reload() in a browser method', () => total() === '2');

    click(window, document.querySelector('.add-all'));
    await until('$this->reload() without names: all of them', () => list() === 'tea tea cake' && total() === '3');
    check('every property takes the page\'s value', text(document.querySelector('.note-shown')) === '' && document.querySelector('.note').value === '');

    click(window, document.querySelector('.add'));
    await until('the page keeps working after a reload', () => list() === 'tea tea cake tea');
    window.close();
});

await scenario('remember', async () => {
    const window = await open('/greeting');
    const { document } = window;
    const draft = () => [document.querySelector('.draft')?.value, text(document.querySelector('.draft-shown'))];

    type(window, document.querySelector('.draft'), 'half done');
    click(window, document.querySelector('.shout'));
    await until('typed and shouted', () => draft()[1] === 'half done' && text(document.querySelector('.shouted')) !== '');

    click(window, document.querySelector('.to-counter'));
    await until('another page', () => window.location.pathname === '/counter' && document.querySelector('.count') !== null);

    window.history.back();
    await until('back brings the #[Remember] property back', () => window.location.pathname === '/greeting' && draft().join('|') === 'half done|half done');
    check('a property that is not remembered starts afresh', text(document.querySelector('.shouted')) === '', text(document.querySelector('.shouted')));

    type(window, document.querySelector('.draft'), 'changed');
    await until('a later change', () => draft()[1] === 'changed');
    window.history.forward();
    await until('forward', () => window.location.pathname === '/counter' && document.querySelector('.count') !== null);
    check('the counter page has nothing remembered', document.querySelector('.draft') === null);
    window.history.back();
    await until('the entry keeps the latest value', () => window.location.pathname === '/greeting' && draft().join('|') === 'changed|changed');
    window.close();
});

await scenario('visible', async () => {
    const window = await open('/feed');
    const { document } = window;
    const items = () => document.querySelectorAll('.items li').length;
    const watched = (el) => window.observers.some((o) => o.elements.has(el));

    check('nitro:visible.200px observes with its margin', window.observers.some((o) => o.options.rootMargin === '200px' && o.elements.has(document.querySelector('.more'))));
    window.observe(document.querySelector('.more'), true);
    await until('in view, it loads until the list is full', () => items() === 20 && document.querySelector('.more') === null);

    const seen = document.querySelector('.seen');
    window.observe(seen, true);
    await until('.once: called', () => text(document.querySelector('.seen')) === '1');
    window.observe(document.querySelector('.seen'), false);
    window.observe(document.querySelector('.seen'), true);
    await wait(100);
    check('.once: the first time only', text(document.querySelector('.seen')) === '1' && !watched(document.querySelector('.seen')));

    window.observe(document.querySelector('.idle'), true);
    await wait(300);
    window.observe(document.querySelector('.idle'), false);
    window.observe(document.querySelector('.idle'), true);
    await wait(300);
    click(window, document.querySelector('.count-pings'));
    await until('a call that changes nothing runs once each time it comes into view', () => text(document.querySelector('.count-pings')) === '2', 3000);
    window.close();
});

await scenario('once, flash and merge', async () => {
    const window = await open('/greeting');
    const { document } = window;
    const sentOnce = [];
    const fetch = window.fetch;
    window.fetch = (input, init = {}) => {
        sentOnce.push(new Headers(init.headers ?? {}).get('X-Nitro-Once'));
        return fetch(input, init);
    };
    const flashes = [];
    window.addEventListener('nitro:flash', (e) => flashes.push(e.detail.flash));

    check('once data on the page', text(document.querySelector('.countries')) === '2 countries', text(document.querySelector('.countries')));
    click(window, document.querySelector('.celebrate'));
    await until('flash data from a #[Server] call', () => text(document.querySelector('.toast')) === 'Well done');
    check('nitro:flash announces it', flashes.some((flash) => flash.toast === 'Well done'), JSON.stringify(flashes));
    check('a #[Server] call says the browser has the once data', sentOnce.at(-1) === 'countries', String(sentOnce));

    click(window, document.querySelector('.to-counter'));
    await until('a visit', () => window.location.pathname === '/counter' && document.querySelector('.count') !== null);
    check('so does a visit', sentOnce.at(-1) === 'countries', String(sentOnce));

    window.history.back();
    await until('back', () => window.location.pathname === '/greeting' && document.querySelector('.greeting') !== null);
    check('the once data is kept though the server left it out', text(document.querySelector('.countries')) === '2 countries', text(document.querySelector('.countries')));
    check('flash data belongs to the page it came with', text(document.querySelector('.toast')) === 'no toast', text(document.querySelector('.toast')));

    click(window, document.querySelector('.celebrate-elsewhere'));
    await until('a call that redirects: its flash data comes with the page it goes to', () => window.location.pathname === '/counter' && window.Nitro.B.flashed('toast') === 'Over here');
    window.close();

    const timeline = await open('/timeline');
    const doc = timeline.document;
    const posts = () => doc.querySelectorAll('.posts li').length;
    check('the first page', posts() === 10, String(posts()));
    click(timeline, doc.querySelector('.more'));
    await until('$loadMore: the next page, appended', () => posts() === 20);
    check('in order', text(doc.querySelectorAll('.posts li')[10]) === 'Post 11', text(doc.querySelectorAll('.posts li')[10]));
    click(timeline, doc.querySelector('.more'));
    await until('until there is no next page', () => posts() === 23 && doc.querySelector('.more') === null);

    click(timeline, doc.querySelector('.reload-tags'));
    click(timeline, doc.querySelector('.reload-settings'));
    await until('merged key by key', () => text(doc.querySelector('.settings')) === 'teal/large');
    check('matched on their id: not doubled', text(doc.querySelector('.tags')) === 'php,js', text(doc.querySelector('.tags')));

    click(timeline, doc.querySelector('.touch'));
    await until('a #[Server] call after them', () => text(doc.querySelector('.touch')) === '1');
    check('the server merged them too (locked properties)', posts() === 23 && text(doc.querySelector('.settings')) === 'teal/large' && text(doc.querySelector('.tags')) === 'php,js', `${posts()} ${text(doc.querySelector('.settings'))} ${text(doc.querySelector('.tags'))}`);
    timeline.close();
});

await scenario('broadcasts', async () => {
    const window = await open('/live');
    const { document } = window;
    const shown = (selector) => text(document.querySelector(selector));

    check('it subscribed to each channel (the private one named by the state)', ['public:orders', 'private:orders.7', 'presence:room', 'private:users.7'].every((key) => window.echoChannels.has(key)), [...window.echoChannels.keys()].join(' '));

    window.echoFire('public', 'orders', 'OrderPlaced', { number: 'A1' });
    await until('a public broadcast runs a browser method', () => shown('.placed') === 'A1');

    window.echoFire('private', 'orders.7', 'OrderShipped', { number: 'B2' });
    await until('a private broadcast calls a #[Server] method with the event', () => shown('.shipped') === 'B2 (server)');

    window.echoFire('presence', 'room', 'here', [{ name: 'Ada' }, { name: 'Lin' }]);
    window.echoFire('presence', 'room', 'joining', { name: 'Max' });
    await until('a presence channel\'s members', () => shown('.members') === 'Ada,Lin,Max');

    window.echoFire('private', 'users.7', 'notification', { message: 'Paid' });
    await until('a notification', () => shown('.note') === 'Paid');

    click(window, document.querySelector('.away'));
    await until('away from the page', () => window.location.pathname === '/greeting');
    check('its listeners stopped with it', window.echoFire('public', 'orders', 'OrderPlaced', { number: 'A2' }) === 0 && window.echoFire('private', 'users.7', 'notification', { message: 'x' }) === 0);
    window.close();
});

await scenario('transitions', async () => {
    const window = await open('/transitions');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);

    /** The built-in fade and scale: inline styles while it runs, put back after. */
    click(window, $('.toggle'));
    await until('it enters', () => $('.panel') !== null);
    check('it starts hidden, with a transition', $('.panel').style.transition.includes('opacity 200ms') && $('.panel').style.transform.includes('scale'), $('.panel').getAttribute('style'));
    await until('it ends as it was', () => $('.panel').getAttribute('style') === null || $('.panel').getAttribute('style') === '', 1000);

    /** Watched rather than sampled after a fixed wait: a slow machine must not miss the fade. */
    const leaving = $('.panel');
    let faded = false;
    const watcher = new window.MutationObserver(() => {
        if (leaving.isConnected && leaving.style.opacity === '0') faded = true;
    });
    watcher.observe(leaving, { attributes: true, attributeFilter: ['style'] });
    click(window, $('.toggle'));
    await until('then it goes', () => $('.panel') === null, 1000);
    watcher.disconnect();
    check('leaving, it stays until its animation ends', faded, String(leaving.getAttribute('style')));

    /** A name: Vue's classes. */
    click(window, $('.toggle-named'));
    await until('a named one enters', () => $('.named') !== null);
    check('with Vue\'s enter classes', $('.named').classList.contains('fade-enter-active'), $('.named').className);
    await until('which go when it is done', () => $('.named').className === 'named', 1000);
    click(window, $('.toggle-named'));
    await wait(5);
    check('and leave classes as it goes', $('.named')?.classList.contains('fade-leave-active') === true, String($('.named')?.className));
    await until('then it goes', () => $('.named') === null, 1000);

    /** A list: rows enter and leave on their own. */
    click(window, $('.add'));
    await until('a new row', () => document.querySelectorAll('.items li').length === 4);
    check('the new row enters', document.querySelectorAll('.items li')[3].style.transition.includes('opacity 150ms'));
    check('the rows already there do not', document.querySelectorAll('.items li')[0].getAttribute('style') === null);
    click(window, $('.remove'));
    await wait(30);
    check('a removed row leaves first', document.querySelectorAll('.items li').length === 4 && text(document.querySelector('.items li')) === '1');
    await until('then goes', () => [...document.querySelectorAll('.items li')].map(text).join(',') === '2,3,4', 1000);

    /** .move: rows that stay slide from where they were (FLIP); the middle one has not moved. */
    const movers = () => [...document.querySelectorAll('.moving li')];
    const row = (n) => movers().find((li) => text(li) === String(n));
    click(window, $('.reverse'));
    await until('reversed', () => movers().map(text).join(',') === '3,2,1');
    check('a row that moved slides', row(1).style.transition.includes('transform 150ms') && row(3).style.transition.includes('transform 150ms'), row(1).getAttribute('style'));
    check('one that did not, does not', !row(2).style.transition, row(2).getAttribute('style'));
    await until('then it is as it was', () => !row(1).getAttribute('style'), 1000);

    /** A row leaving moves out of the flow, so those after it slide up while it fades. */
    click(window, $('.drop'));
    await until('it leaves', () => row(3)?.style.position === 'absolute');
    check('those after it slide up', row(2).style.transition.includes('transform 150ms'), row(2).getAttribute('style'));
    await until('then it goes', () => movers().map(text).join(',') === '2,1', 1000);

    /** .out-in: the new one shows once the old one has left. */
    click(window, $('.switch-tab'));
    await until('switching', () => $('.tab-b') !== null);
    check('out-in: the new one waits, hidden', $('.tab-b').style.display === 'none' && $('.tab-a') !== null);
    await until('the old one is gone', () => $('.tab-a') === null, 1000);
    await until('then the new one shows and enters', () => $('.tab-b').style.display !== 'none', 1000);

    /** .in-out: the old one leaves once the new one has entered. */
    click(window, $('.switch-pane'));
    await until('switching', () => $('.pane-y') !== null);
    await wait(40);
    check('in-out: the old one stays while the new one enters', $('.pane-x') !== null && $('.pane-x').style.opacity !== '0', String($('.pane-x')?.getAttribute('style')));
    await until('then the old one leaves', () => $('.pane-x') === null, 1500);
    window.close();
});

await scenario('islands', async () => {
    const window = await open('/islands');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);
    const live = (section) => window.Nitro.components().filter((c) => $(`.${section}`).contains(c.el)).length;
    const chunk = () => [...document.querySelectorAll('script[src]')].some((s) => s.src.includes(chunkId('islands')));

    check('a component without nitro:hydrate starts with the page', live('now') === 1);
    check('islands wait, as the server rendered them', live('media') + live('visible') + live('interaction') + live('typing') === 0 && text($('.media .count')) === '0');
    check('marked for the browser', $('.visible .beacon')?.getAttribute('nitro:hydrate') === 'visible:100px' && $('.media .counter')?.getAttribute('nitro:hydrate') === 'media:(min-width: 1200px)');
    check('the chunk of an island that waits is not loaded with the page', !chunk());
    check('a static island is its HTML, without state', $('.static .counter') !== null && $('.static [nitro\\:snapshot]') === null && !$('.static .counter').hasAttribute('nitro:id') && live('static') === 0);

    /** idle: once the browser has nothing else to do. */
    await until('idle: it starts', () => live('idle') === 1, 2000);
    click(window, $('.idle .counter').querySelectorAll('button')[1]);
    await until('and works', () => text($('.idle .count')) === '1');

    /** Slots from the Blade view: no parent renders them in the browser, so their HTML comes with the state. */
    await until('a slotted island starts', () => live('slotted') === 1, 2000);
    check('it keeps its slots', text($('.slotted .from-blade')) === 'From the Blade view' && text($('.slotted .foot')) === 'Its footer' && $('.slotted .blank') === null, $('.slotted')?.innerHTML);
    click(window, $('.slotted .toggle'));
    await until('hidden', () => $('.slotted .body') === null);
    click(window, $('.slotted .toggle'));
    await until('and rendered again in the browser', () => text($('.slotted .from-blade')) === 'From the Blade view');
    check('once', $('.slotted').querySelectorAll('.from-blade').length === 1);

    /** media: when the query matches. */
    await wait(30);
    check('media: not while the query does not match', live('media') === 0);
    window.setMedia('(min-width: 1200px)', true);
    await until('media: when it matches', () => live('media') === 1);

    /** interaction: focus starts it, its chunk loads then, and what is typed meanwhile reaches nitro:model. */
    type(window, $('.typing .text'), 'hello');
    check('its chunk is loading', live('typing') === 0);
    await until('focus starts it', () => live('typing') === 1);
    await until('and what was typed while it loaded arrives', () => text($('.typing .echo')) === 'hello');
    check('its chunk loaded when it started', chunk());

    /** The first click starts one, and is not lost. */
    click(window, $('.interaction .hit'));
    await until('interaction: the click starts it', () => live('interaction') === 1);
    await until('and the click counts', () => text($('.interaction .hits')) === '1');
    check('only those islands started', live('visible') === 0);

    /** visible: in view (or within its margin). */
    check('the observer has the margin', window.observers.some((o) => o.options?.rootMargin === '100px'));
    window.observe($('.visible .beacon'), true);
    await until('visible: in view, it starts', () => live('visible') === 1);
    click(window, $('.visible .hit'));
    await until('and works', () => text($('.visible .hits')) === '1');
    window.close();
});

/** nitro:navigate to a page that is not Nitro's (a Blade view), and from it: its HTML, still without reloading. */
/**
 * A markup-first page (#[Navigate('markup')]): the visit renders it at once, its placeholders
 * showing, before its (slow) data; what is typed meanwhile is kept; the data fills it in, then its
 * deferred property loads, and a #[Server] call works on the state that came.
 */
await scenario('markup-first navigation', async () => {
    const window = await open('/greeting');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);
    const runtime = window.Nitro;

    click(window, $('.to-inbox'));
    await until('rendered at once, before its data', () => window.location.pathname === '/inbox' && $('.heading') !== null, 300);
    check('its placeholder shows while the data loads', $('.loading') !== null && $('.messages') === null);

    type(window, $('.draft'), 'typed early');
    await until('the data fills it in', () => document.querySelectorAll('.messages li').length === 2, 3000);
    check('the placeholder is gone', $('.loading') === null);
    check('what was typed before the data is kept', $('.draft').value === 'typed early', $('.draft').value);
    await until('then its deferred property loads', () => text($('.unread')) === '3 unread');

    click(window, $('.send'));
    await until('a #[Server] call works on the state that came', () => text($('.send')) === '1');
    check('no reload', window.Nitro === runtime);

    click(window, $('.to-greeting'));
    await until('a data-first page after it', () => window.location.pathname === '/greeting' && $('.greeting') !== null);
    check('still no reload', window.Nitro === runtime);
    window.close();
});

/** @nitroHead: a visit puts the new page's title and tags in place of the last page's. */
await scenario('head', async () => {
    const window = await open('/article');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);
    const meta = (name) => document.head.querySelectorAll(`meta[name="${name}"]`);
    const runtime = window.Nitro;

    check('the server\'s head', document.title === 'Nitro & Blade · Fixture' && meta('author').length === 1, document.title);

    click(window, $('.to-notice'));
    await until('the visit\'s title', () => document.title === 'Notice · Fixture');
    check('its description, once', meta('description').length === 1 && meta('description')[0].getAttribute('content') === 'A private notice');
    check('its noindex', meta('robots').length === 1);
    check('the last page\'s tags are gone', meta('author').length === 0 && document.head.querySelector('link[rel="canonical"]') === null);

    click(window, $('.to-article'));
    await until('back on the article', () => document.title === 'Nitro & Blade · Fixture');
    check('its tags again, no noindex', meta('robots').length === 0 && meta('author').length === 1 && document.head.querySelector('link[rel="canonical"]') !== null);
    check('no reload', window.Nitro === runtime);
    window.close();
});

/**
 * A client page (#[Navigate('client')]): a visit renders it in the browser without asking the
 * server, its title and head worked out at build time; back and forward the same.
 */
await scenario('client navigation', async () => {
    const window = await open('/article');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);
    const runtime = window.Nitro;
    const asked = [];
    const fetch = window.fetch;
    window.fetch = (input, init) => {
        asked.push(new URL(input, window.location.href).pathname);
        return fetch(input, init);
    };

    click(window, $('.to-terms'));
    await until('the client page renders', () => window.location.pathname === '/terms' && $('.terms') !== null);
    check('its title, from the build', document.title === 'Terms · Fixture', document.title);
    check('its head tags', document.head.querySelector('meta[name="description"]')?.getAttribute('content') === 'The terms of the Nitro test app');
    check('no request to the server for it', !asked.includes('/terms'), asked.join(' '));

    click(window, $('.agree'));
    await until('its browser methods work', () => text($('.agree')) === 'Agreed');

    click(window, $('.to-article'));
    await until('a server page after it', () => window.location.pathname === '/article' && $('.article') !== null);
    check('which the server answered', asked.includes('/article'));

    window.history.back();
    await until('back to the client page', () => window.location.pathname === '/terms' && $('.terms') !== null);
    check('back, still without a request', asked.filter((path) => path === '/terms').length === 0);
    check('no reload', window.Nitro === runtime);

    /** A client page with route parameters: taken from the URL in the browser, into the properties of the same name. */
    click(window, $('.to-chapter'));
    await until('/chapters/3/intro renders in the browser', () => window.location.pathname === '/chapters/3/intro' && text($('.number')) === 'Chapter 3');
    check('each parameter in its property, as its type', text($('.part')) === 'intro' && text($('.next')) === 'Next' && $('.next').getAttribute('href') === '/chapters/4', $('.next')?.getAttribute('href'));
    check('without a request', !asked.some((path) => path.startsWith('/chapters')), asked.join(' '));
    click(window, $('.read'));
    await until('its browser methods work', () => text($('.read')) === 'Read');

    click(window, $('.next'));
    await until('the next chapter, its optional part left out', () => window.location.pathname === '/chapters/4' && text($('.number')) === 'Chapter 4' && text($('.part')) === 'whole');
    check('still without a request', !asked.some((path) => path.startsWith('/chapters')), asked.join(' '));
    check('no reload', window.Nitro === runtime);

    /** /chapters/05 matches the route's digits, but is no int as the server reads one: the server answers it. */
    const before = asked.length;
    window.Nitro.visit('/chapters/05');
    await until('a parameter that is not its type goes to the server', () => asked.slice(before).includes('/chapters/05'));
    /** Its answer (a 404) loads in full, as an error page does: let the visit finish before the window goes. */
    await new Promise((resolve) => setTimeout(resolve, 500));
    window.close();
});

/**
 * Nested layouts: a visit from a page in the app's layout to one in a layout nested in it changes
 * only the app's main area (nitro:layout="app"); the nav around it stays, its components too.
 */
await scenario('nested layouts', async () => {
    const window = await open('/counter');
    const { document } = window;
    const nav = document.querySelector('nav');
    nav.dataset.kept = 'yes';
    const main = document.querySelector('[nitro\:layout="app"]');
    click(window, document.querySelector('.nested-link'));
    await until('the nested page is there', () => window.location.pathname === '/nested' && byName(document, 'pages::nested') !== null);
    check('the nav around the main area stayed: not loaded again', document.querySelector('nav') === nav && nav.dataset.kept === 'yes');
    check('the main area is the same element, its content the nested layout\'s', document.querySelector('[nitro\:layout="app"]') === main && main.querySelector('.nested-side') !== null);
    check('the old page\'s components left', byName(document, 'pages::counter') === null);
    const tap = document.querySelector('.tap');
    click(window, tap);
    await until('the nested page is live', () => text(document.querySelector('.tap')) === 'Tapped 1');
    window.history.back();
    await until('back to the app\'s page', () => window.location.pathname === '/counter' && byName(document, 'pages::counter') !== null);
    check('the nav stayed going back too', document.querySelector('nav') === nav && nav.dataset.kept === 'yes');
    window.close();
});

/** nitro:prefetch.visible: the page is asked for once its link comes into view, and the click takes it. */
await scenario('prefetch in view', async () => {
    const window = await open('/counter');
    const { document } = window;
    let asked = 0;
    const fetch = window.fetch;
    window.fetch = (input, init) => {
        const url = new URL(input, window.location.href);
        if (url.pathname === '/journal' && new Headers(init?.headers ?? {}).has('X-Nitro-Navigate')) asked++;
        return fetch(input, init);
    };
    const link = document.querySelector('.visible-link');
    await new Promise((resolve) => setTimeout(resolve, 100));
    check('out of view: not asked for', asked === 0, String(asked));
    window.observe(link, true);
    await until('in view: its page asked for', () => asked === 1);
    window.observe(link, true);
    await new Promise((resolve) => setTimeout(resolve, 100));
    check('once: no longer watched', asked === 1, String(asked));
    click(window, link);
    await until('the click shows the prefetched page', () => window.location.pathname === '/journal' && byName(document, 'pages::journal') !== null);
    check('without asking again', asked === 1, String(asked));
    window.close();
});

/**
 * nitro:prefetch and back/forward: pages asked for once. Hovering asks for the page; the
 * click takes it; back and forward show visited pages; a #[Server] call forgets them all.
 */
await scenario('prefetch and back/forward', async () => {
    const window = await open('/counter');
    const { document } = window;
    const asked = { '/counter': 0, '/board': 0 };
    const fetch = window.fetch;
    window.fetch = (input, init) => {
        const url = new URL(input, window.location.href);
        if (url.pathname in asked && new Headers(init?.headers ?? {}).has('X-Nitro-Navigate')) asked[url.pathname]++;
        return fetch(input, init);
    };
    const at = (path) => window.location.pathname === path && document.querySelector('[nitro\\:page]') !== null;
    const link = document.querySelector('.prefetch-link');
    const requests = [];
    window.addEventListener('nitro:request', (event) => requests.push(`${event.detail.component}.${event.detail.method}`));

    link.dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true }));
    await until('resting on a prefetch link asks for its page', () => asked['/board'] === 1);
    click(window, link);
    await until('the click shows it: the prefetched page', () => at('/board') && byName(document, 'pages::board') !== null);
    check('without asking again', asked['/board'] === 1, JSON.stringify(asked));

    window.history.back();
    await until('back to the first page (loaded in full, so not kept: asked for)', () => at('/counter') && byName(document, 'pages::counter') !== null && asked['/counter'] === 1);
    window.history.forward();
    await until('forward: the visited page', () => at('/board') && byName(document, 'pages::board') !== null);
    check('shown without asking', asked['/board'] === 1, JSON.stringify(asked));
    window.history.back();
    await until('back again: visited now, shown', () => at('/counter') && byName(document, 'pages::counter') !== null);
    check('without asking', asked['/counter'] === 1, JSON.stringify({ asked, requests }));

    /** A #[Server] call may change what the kept pages show: they are forgotten. */
    const save = [...document.querySelectorAll('button')].find((button) => text(button) === 'Save');
    click(window, save);
    await until('the #[Server] call answered', () => !save.disabled && window.Nitro.components().every((c) => !c.loading?.size));
    await new Promise((resolve) => setTimeout(resolve, 100));
    window.history.forward();
    await until('after it, forward asks the server again', () => at('/board') && byName(document, 'pages::board') !== null && asked['/board'] === 2, 3000);
    window.close();
});

/** A link to a part of the page (#hash), plain or nitro:navigate, and back between parts: scrolled, never visited. */
await scenario('links to a part of the page', async () => {
    const window = await open('/counter');
    const { document } = window;
    const runtime = window.Nitro;
    const page = byName(document, 'pages::counter');
    const requests = [];
    const fetch = window.fetch;
    window.fetch = (...args) => {
        requests.push(String(args[0]?.url ?? args[0]));
        return fetch(...args);
    };
    /** jsdom does not scroll: what scrolls is recorded. */
    const scrolls = [];
    window.scrollTo = (x, y) => scrolls.push(`${x},${y}`);
    const partOf = (id) => {
        const part = document.createElement('h2');
        part.id = id;
        part.scrollIntoView = () => scrolls.push(`#${id}`);
        document.body.append(part);
    };
    const link = (href, navigate) => {
        const a = document.createElement('a');
        a.href = href;
        if (navigate) a.setAttribute('nitro:navigate', '');
        document.body.append(a);
        return a;
    };
    partOf('first');
    partOf('second');

    click(window, link('#first', false));
    await until('a plain link to a part: the address names it', () => window.location.hash === '#first');
    click(window, link('/counter#second', true));
    await until('a nitro:navigate link to a part of this page: the address names it', () => window.location.hash === '#second');

    window.history.back();
    await until('back: to the part before', () => window.location.hash === '#first' && scrolls.includes('#first'));
    window.history.back();
    await until('back again: the page as it was, at the top', () => window.location.hash === '' && scrolls.at(-1) === '0,0');
    await wait(150);

    check('none of it asked the server', requests.length === 0, requests.join(' '));
    check('the page was neither reloaded nor rendered again', window.Nitro === runtime && byName(document, 'pages::counter') === page);
    window.close();
});

/**
 * {!! !!} HTML that a script on the page changed before Nitro started (an anchor link added to a
 * heading, as docs pages do): taken over as it is, the same element, the change kept.
 */
await scenario('raw HTML a script changed before the runtime started', async () => {
    const window = await open('/excerpt', {
        beforeParse(window) {
            window.document.addEventListener('nitro:starting', () => {
                const body = window.document.querySelector('.excerpt article p.body');
                if (!body) return;
                body.dataset.mark = 'the server\'s';
                const anchor = window.document.createElement('a');
                anchor.className = 'anchor';
                anchor.textContent = '#';
                body.append(anchor);
            });
        },
    });
    const { document } = window;
    await until('the excerpt started', () => window.Nitro?.components().some((component) => component.el.classList.contains('excerpt')));
    const body = document.querySelector('.excerpt article p.body');

    check('the element the server sent is the one on the page', body?.dataset.mark === 'the server\'s', body?.outerHTML);
    check('what the script added to it is kept', body?.querySelector('a.anchor')?.textContent === '#', body?.outerHTML);
    check('the #[Html] text is the server\'s', body?.firstChild?.nodeValue === 'Hello <world>', body?.outerHTML);
    window.close();
});

await scenario('navigate to and from a Blade view', async () => {
    const window = await open('/counter');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);
    const live = (section) => window.Nitro.components().filter((c) => $(`.${section}`)?.contains(c.el)).length;
    const link = (href) => {
        const a = document.createElement('a');
        a.href = href;
        a.setAttribute('nitro:navigate', '');
        a.textContent = href;
        document.body.appendChild(a);
        return a;
    };
    const fetched = [];
    const pageFetch = window.fetch;
    window.fetch = async (...args) => {
        const response = await pageFetch(...args);
        fetched.push(`${new URL(response.url).pathname} ${response.headers.get('Content-Type')?.split(';')[0]}`);
        return response;
    };
    const runtime = window.Nitro;
    /** Something a script added to the body (the application's own progress bar, a widget): not the page's. */
    const widget = document.createElement('div');
    widget.className = 'widget';
    document.body.appendChild(widget);

    click(window, link('/islands'));
    await until('the progress bar starts', () => document.getElementById('nprogress') !== null);
    const bar = document.getElementById('nprogress');
    await until('to the Blade view, without reloading', () => window.location.pathname === '/islands' && live('now') === 1);
    check('the progress bar goes on into the new page, to finish there', document.querySelector('#nprogress .bar') !== null);
    check('the same bar, never moved (moved, its running transition would stop)', document.getElementById('nprogress') === bar && bar.parentNode === document.body);
    check('what a script added to the body stays, as it was', widget.isConnected && widget.parentNode === document.body && document.querySelectorAll('.widget').length === 1);
    check('one request, its HTML', fetched.length === 1 && fetched[0] === '/islands text/html', fetched.join(', '));
    check('the same runtime', window.Nitro === runtime);
    check('its title', document.title === 'Islands', document.title);
    check('the old page\'s components are gone', !window.Nitro.components().some((c) => c.name === N('pages::counter')));
    check('nitro:static stays its HTML', $('.static .counter') !== null && live('static') === 0);
    await until('its islands start', () => live('idle') === 1, 2000);
    click(window, $('.now .counter').querySelectorAll('button')[1]);
    await until('and work', () => text($('.now .count')) === '1');
    await until('the progress bar completes and goes', () => $('#nprogress') === null);

    fetched.length = 0;
    click(window, link('/counter'));
    await until('back to a Nitro page, without reloading', () => window.location.pathname === '/counter' && byName(document, 'pages::counter') !== null);
    check('its HTML, as the Blade view has no page to render into', fetched.length === 1 && fetched[0] === '/counter text/html', fetched.join(', '));
    check('the islands are gone', $('.now') === null && !window.Nitro.components().some((c) => c.name === N('counter')));
    const counter = byName(document, 'pages::counter');
    click(window, counter.querySelectorAll('button')[1]);
    await until('the page is live', () => text(counter.querySelector('.count')) === '1');

    fetched.length = 0;
    click(window, document.querySelector('a.board-link'));
    await until('then a Nitro page renders its data as before', () => window.location.pathname === '/board' && document.querySelector('[nitro\\:name="todo-list"] li.todo') !== null);
    check('as JSON', fetched.length === 1 && /application\/json/.test(fetched[0]), fetched.join(', '));

    window.history.back();
    await until('back', () => window.location.pathname === '/counter' && byName(document, 'pages::counter') !== null);
    window.history.back();
    await until('back to the Blade view', () => window.location.pathname === '/islands' && live('now') === 1);
    check('the same runtime still', window.Nitro === runtime);
    window.fetch = pageFetch;
    window.close();
});

await scenario('a page and its code from different builds', async () => {
    const window = await open('/counter');
    window.sessionStorage.setItem('nitro:stale', window.location.href);
    check('loaded again once only: the second time in a row, it is reported instead', window.Nitro.stale() === false && window.Nitro.reloading === false);
    window.close();
});

await scenario('stress', async () => {
    const window = await open('/stress');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);
    const rows = () => [...document.querySelectorAll('.orders > li')];
    const customers = () => rows().map((row) => text(row.querySelector('.pick'))).join(',');
    const logText = () => text($('.log'));

    check('the rows render, by id', customers() === 'Ada <b>,Grace,Linus', customers());
    const unsaved = () => $('.unsaved').hasAttribute('data-nitro-dirty');
    const anyChange = () => $('.any-change').classList.contains('changed') && $('.any-change').hasAttribute('data-changed');
    check('nitro:dirty: nothing changed yet', !unsaved() && !anyChange() && window.getComputedStyle($('.unsaved')).display === 'none');
    check('the grand total: #[Computed] calling a #[Browser] class', text($('.grand')) === '£140.79', text($('.grand')));
    check('@forelse with @break and $loop', text(rows()[2].querySelector('.bulk')) === '1/1 bulk: Jalebi' && rows()[2].querySelectorAll('.lines li').length === 1, rows()[2].innerHTML);
    check('@empty', text(rows()[1].querySelector('.none')) === 'No lines');
    check('a custom directive with {{ }} in its value', rows()[0].dataset.tip === 'Ada <b> (2)', rows()[0].dataset.tip);
    check('@class from a prop and the enum\'s value', rows()[0].classList.contains('focus') && !rows()[2].classList.contains('focus'), rows()[0].className);

    /** #[Url] properties: the filter (live) and the sort (aliased "by"). */
    type(window, $('.filter'), 'a');
    await until('the filter narrows the rows', () => customers() === 'Ada <b>,Grace');
    check('and is in the URL', new window.URL(window.location.href).searchParams.get('filter') === 'a', window.location.href);
    type(window, $('.filter'), '');
    await until('all rows again', () => customers() === 'Ada <b>,Grace,Linus');
    $('.sort').value = 'total';
    change(window, $('.sort'));
    await until('usort with a match in the closure', () => customers() === 'Linus,Ada <b>,Grace');
    check('the sort in the URL as "by"', new window.URL(window.location.href).searchParams.get('by') === 'total', window.location.href);

    /** A child's event reaches the page's #[On]: the page re-renders the row's prop and the teleported summary. */
    click(window, rows()[1].querySelector('.pick'));
    await until('the picked row is selected', () => rows()[1].classList.contains('selected'));
    check('only that row', rows().filter((row) => row.classList.contains('selected')).length === 1);
    await until('the @teleport content follows', () => text($('#dock .dock-summary')) === 'Picked #1 · 1 actions');

    /** Deep writes: $this->orders[$i]['lines'][$line]['qty']++, through $loop->parent. */
    const bumps = () => [...document.querySelectorAll('.bumps button')];
    check('$loop->parent->iteration', bumps().map(text).join(',') === '1.1,1.2,up 1,up 2,3.1,up 3', bumps().map(text).join(','));
    click(window, bumps()[0]);
    await until('the nested qty grows', () => text(rows()[1].querySelector('.line')) === 'Rasmalai × 3 = £13.50');
    check('the grand total follows', text($('.grand')) === '£145.29', text($('.grand')));

    /** Array swap by destructuring: $orders changes order, the sorted rows (by id) do not. */
    $('.sort').value = 'id';
    change(window, $('.sort'));
    await until('by id again', () => customers() === 'Ada <b>,Grace,Linus');
    click(window, bumps().find((b) => text(b) === 'up 3'));
    await until('Linus moved up in $orders', () => bumps().map(text).join(',') === '1.1,1.2,up 1,2.1,up 3,up 2');
    check('the sorted rows stay by id', customers() === 'Ada <b>,Grace,Linus', customers());
    check('the selection stays with its row', rows()[0].classList.contains('selected') && rows().filter((row) => row.classList.contains('selected')).length === 1);
    check('the log', logText() === 'pick 1, bump 1.0, up 3', logText());

    /** An enum property: a method on the case assigns the next case. */
    check('the focus label', text($('.advance')) === 'Focus: Paid');
    click(window, $('.advance'));
    await until('$this->focus->next()', () => text($('.advance')) === 'Focus: On its way');
    await until('the rows get the new focus', () => rows()[2].classList.contains('focus') && !rows()[0].classList.contains('focus'));

    /** The draft: lines added and removed, a #[Modelable] child bound to a nested path, then a #[Server] save. */
    const qtys = () => [...document.querySelectorAll('.line-qty')].map((input) => input.value).join(',');
    const children = () => [...document.querySelectorAll('.draft-line')].map((line) => byName(line, 'qty-input'));
    click(window, $('.add-line'));
    await until('a line added, with its own child', () => children().length === 2 && children().every(Boolean));
    click(window, children()[1].querySelector('.more'));
    click(window, children()[1].querySelector('.more'));
    await until('nitro:model="draft.lines.{{ $i }}.qty": the second child writes its own line', () => qtys() === '1,3');
    check('and shows it', text(children()[1].querySelector('b')) === '3' && text(children()[0].querySelector('b')) === '1');
    click(window, document.querySelectorAll('.draft-line .drop')[1]);
    await until('and removed (array_splice)', () => document.querySelectorAll('.draft-line').length === 1);
    click(window, children()[0].querySelector('.more'));
    await until('the first child writes the first line', () => qtys() === '2');

    const hidden = (selector) => $(selector).hasAttribute('data-nitro-loading-hidden');
    check('nitro:loading.delay: hidden before any call', hidden('.saving') && hidden('.still-saving'));
    const posted = $('.draft').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    check('nitro:submit without .prevent: the browser does not post the form', posted === false);
    check('nitro:dirty with no target: any change the server has not seen', anyChange() && !unsaved());
    await until('#[Validate] with nested rules: the error shows', () => text($('.draft .error')) === 'The draft.customer field is required.');
    check('nothing saved', rows().length === 3);
    await until('a call refused by #[Validate] is quick: the delayed ones never showed', () => hidden('.saving') && !$('.save').classList.contains('busy'));
    type(window, $('.customer'), 'Zed');
    change(window, $('.customer'));
    await until('nitro:dirty nitro:target="draft.customer": shown once the name changes', () => unsaved() && window.getComputedStyle($('.unsaved')).display !== 'none');
    type(window, $('.customer'), '');
    await until('typed back to what the server has: not changed', () => !unsaved());
    type(window, $('.customer'), 'Zed');
    await until('changed again', () => unsaved());
    click(window, $('.save'));
    await wait(30);
    check('nitro:loading.delay: not yet, the call has only just started', hidden('.saving') && !$('.save').classList.contains('busy'));
    await until('nitro:loading.delay.100ms: shown once the call has taken 100ms', () => !hidden('.saving') && $('.save').classList.contains('busy'));
    check('nitro:loading.delay.2s: not for a call shorter than that', hidden('.still-saving'));
    await until('saved on the server: the new row', () => customers() === 'Ada <b>,Grace,Linus,Zed');
    await until('saved: nothing the server has not seen', () => !unsaved() && !anyChange());
    await until('the call is over: hidden again, the class gone', () => hidden('.saving') && hidden('.still-saving') && !$('.save').classList.contains('busy'));
    check('the server kept the swapped order', bumps().map(text).join(',') === '1.1,1.2,up 1,2.1,up 3,up 2,4.1,up 4', bumps().map(text).join(','));
    check('with its line', text(rows()[3].querySelector('.line')) === 'Ladoo × 2 = £3.00', text(rows()[3].querySelector('.line')));
    check('the error is gone', $('.draft .error') === null);
    check('the draft is reset', $('.customer').value === '' && document.querySelectorAll('.draft-line').length === 0);
    check('the id is the server\'s', logText().endsWith('saved 4'), logText());
    await until('the summary counts every action', () => text($('#dock .dock-summary')) === 'Picked #1 · 4 actions');

    /** Nitro.visit(): a nitro:navigate visit from a script, without reloading. */
    window.stayed = true;
    window.Nitro.visit('/greeting');
    await until('Nitro.visit() goes there', () => window.location.pathname === '/greeting' && byName(document, 'pages::greeting') !== null);
    check('without reloading', window.stayed === true);
    window.close();
});

await scenario('view engines', async () => {
    const window = await open('/engines');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);
    const plain = (root) => ({
        label: text(root.querySelector('.label')),
        count: text(root.querySelector('.count')),
        doubled: text(root.querySelector('.doubled')),
    });

    /** Mounted by its engine in the element the server rendered, from the state on it. */
    await until('the JavaScript view mounts', () => plain($('.plain-side')).count === '3');
    check('it reads properties and #[Computed] values by their PHP names', JSON.stringify(plain($('.plain-side'))) === '{"label":"Server","count":"3","doubled":"6"}', JSON.stringify(plain($('.plain-side'))));
    check('the handle lists the names', text($('.plain-side .names')) === 'count,label | doubled,increment | save | doubled', text($('.plain-side .names')));

    click(window, $('.plain-side .inc'));
    await until('a browser method from the view: the state changes, the view hears it', () => plain($('.plain-side')).count === '5' && plain($('.plain-side')).doubled === '10');
    check('by the PHP names', window.plainUpdates.includes('count'), window.plainUpdates.join(' / '));

    /** Side by side: the Blade component on the same page is its own. */
    click(window, document.querySelectorAll('.blade-side button')[1]);
    await until('the Blade component works beside it', () => text($('.blade-side .count')) === '1');
    check('and the other is unchanged', plain($('.plain-side')).count === '5');

    click(window, $('.plain-side .save'));
    await until('a #[Server] method: the promise gives its value', () => text($('.plain-side .saved')) === 'saved 5');

    /** Made in the browser: its engine mounts it in a new element; it unmounts when it goes. */
    click(window, $('.more'));
    await until('a component made in the browser mounts too', () => $('.plain-made') && plain($('.plain-made')).count === '10');
    check('with its props', plain($('.plain-made')).label === 'Browser' && plain($('.plain-made')).doubled === '20');
    click(window, $('.plain-made .inc'));
    await until('it has its own state', () => plain($('.plain-made')).count === '12' && plain($('.plain-side')).count === '5');
    click(window, $('.more'));
    await until('gone: unmounted', () => $('.plain-made') === null && window.plainUnmounts === 1);

    /** Vue: <script setup> with useNitro(); the names a template uses bare. */
    const vue = (selector) => $(`.vue-side ${selector}`);
    await until('the Vue view mounts, from the state', () => text(vue('.count')) === '1' && text(vue('.doubled')) === '2');
    click(window, vue('.inc'));
    await until('a browser method (compiled PHP) from Vue: the view follows', () => text(vue('.count')) === '4' && text(vue('.doubled')) === '8');
    click(window, vue('.local'));
    await until('a ref written in Vue goes to the component', () => text(vue('.count')) === '5' && text(vue('.doubled')) === '10');
    check('the component has it', window.Nitro.components().find((c) => c.name === N('vue-counter')).unsaved().includes(P_('count')));

    /** The Blade page around it renders again: Vue's elements are Vue's, untouched. */
    const vueCount = vue('.count');
    click(window, $('.more'));
    await until('the page renders around the views', () => $('.plain-made') !== null);
    click(window, $('.more'));
    await until('and again', () => $('.plain-made') === null);
    check('Vue\'s elements are the same, with what Vue shows', vue('.count') === vueCount && text(vueCount) === '5', text(vue('.count')));

    /** A #[Server] method: its status while it runs, the errors, its value. */
    click(window, vue('.save'));
    await until('save.processing while it runs', () => vue('.busy') !== null && vue('.save').disabled);
    await until('the errors come back', () => text(vue('.error')) === 'The title field is required.' && vue('.busy') === null && !vue('.save').disabled);
    check('the call failed validation: its promise says so', text(vue('.said')) === '' || text(vue('.said')) === 'failed', text(vue('.said')));
    type(window, vue('.title'), 'Plan');
    click(window, vue('.save'));
    await until('saved: the value comes back, the error goes', () => text(vue('.said')) === 'saved Plan at 5' && text(vue('.error')) === '');

    /** A template alone: the names bare, v-model deep into an array property. */
    const note = (selector) => $(`.vue-note-side ${selector}`);
    await until('the template-only view mounts', () => text(note('.shown')) === 'hi' && text(note('.tags')) === 'a');
    type(window, note('.text'), 'hey');
    await until('v-model writes the property', () => text(note('.shown')) === 'hey');
    click(window, note('.shout'));
    await until('a browser method reads what v-model wrote', () => text(note('.shown')) === 'HEY!' && note('.text').value === 'HEY!');
    type(window, note('.email'), 'a@b.c');
    await until('v-model deep in an array', () => text(note('.email-shown')) === 'a@b.c');
    click(window, note('.tag'));
    await until('a method changing the array keeps what v-model wrote in it', () => text(note('.tags')) === 'a,b' && note('.email').value === 'a@b.c' && text(note('.email-shown')) === 'a@b.c');

    /** React: the component as the view's props; useNitro() in a component inside it. React tracks an input's value, so typing goes through the native setter, as a keystroke does. */
    const reactType = (el, value) => {
        Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(el, value);
        el.dispatchEvent(new window.Event('input', { bubbles: true }));
    };
    const react = (selector) => $(`.react-side ${selector}`);
    await until('the React view mounts, from the state', () => text(react('.count')) === '1' && text(react('.doubled')) === '2');
    click(window, react('.inc'));
    await until('a browser method (compiled PHP) from React: the view follows', () => text(react('.count')) === '4' && text(react('.doubled')) === '8');
    click(window, react('.local'));
    await until('set() from React goes to the component', () => text(react('.count')) === '5' && text(react('.doubled')) === '10');
    check('the component has it', window.Nitro.components().find((c) => c.name === N('react-counter')).unsaved().includes(P_('count')));

    click(window, react('.save'));
    await until('save.processing while it runs', () => react('.busy') !== null && react('.save').disabled);
    await until('the errors come back', () => text(react('.error')) === 'The title field is required.' && react('.busy') === null && !react('.save').disabled);
    reactType(react('.title'), 'Plan');
    await until('a controlled input follows set()', () => react('.title').value === 'Plan');
    click(window, react('.save'));
    await until('saved: the value comes back, the error goes', () => text(react('.said')) === 'saved Plan at 5' && text(react('.error')) === '');

    const reactNote = (selector) => $(`.react-note-side ${selector}`);
    await until('the useNitro() view mounts', () => text(reactNote('.shown')) === 'hi' && text(reactNote('.tags')) === 'a');
    reactType(reactNote('.text'), 'hey');
    await until('set() writes the property', () => text(reactNote('.shown')) === 'hey');
    click(window, reactNote('.shout'));
    await until('a browser method reads what set() wrote', () => text(reactNote('.shown')) === 'HEY!' && reactNote('.text').value === 'HEY!');
    reactType(reactNote('.email'), 'a@b.c');
    await until('set() of a key under an array, from a component inside the view', () => text(reactNote('.email-shown')) === 'a@b.c');
    click(window, reactNote('.tag'));
    await until('a method changing the array keeps what set() wrote in it', () => text(reactNote('.tags')) === 'a,b' && reactNote('.email').value === 'a@b.c');

    /** Solid: the component as reactive props, a store updated where it changed; useNitro() in a component inside. */
    const solid = (selector) => $(`.solid-side ${selector}`);
    await until('the Solid view mounts, from the state', () => text(solid('.count')) === '1' && text(solid('.doubled')) === '2');
    const solidCount = solid('.count');
    click(window, solid('.inc'));
    await until('a browser method (compiled PHP) from Solid: the view follows', () => text(solid('.count')) === '4' && text(solid('.doubled')) === '8');
    check('fine-grained: the same element, its text changed', solid('.count') === solidCount);
    click(window, solid('.local'));
    await until('set() from Solid goes to the component', () => text(solid('.count')) === '5' && text(solid('.doubled')) === '10');
    check('the component has it', window.Nitro.components().find((c) => c.name === N('solid-counter')).unsaved().includes(P_('count')));
    click(window, solid('.save'));
    await until('save.processing while it runs', () => solid('.busy') !== null && solid('.save').disabled);
    await until('the errors come back', () => text(solid('.error')) === 'The title field is required.' && solid('.busy') === null && !solid('.save').disabled);
    type(window, solid('.title'), 'Plan');
    click(window, solid('.save'));
    await until('saved: the value comes back, the error goes', () => text(solid('.said')) === 'saved Plan at 5' && text(solid('.error')) === '');

    const solidNote = (selector) => $(`.solid-note-side ${selector}`);
    await until('the useNitro() view mounts', () => text(solidNote('.shown')) === 'hi' && text(solidNote('.tags')) === 'a');
    type(window, solidNote('.text'), 'hey');
    await until('set() writes the property', () => text(solidNote('.shown')) === 'hey');
    click(window, solidNote('.shout'));
    await until('a browser method reads what set() wrote', () => text(solidNote('.shown')) === 'HEY!' && solidNote('.text').value === 'HEY!');
    type(window, solidNote('.email'), 'a@b.c');
    await until('set() of a key under an array, from a component inside the view', () => text(solidNote('.email-shown')) === 'a@b.c');
    click(window, solidNote('.tag'));
    await until('a method changing the array keeps what set() wrote in it', () => text(solidNote('.tags')) === 'a,b' && solidNote('.email').value === 'a@b.c');

    /** Components written in their views: the PHP in the .vue file's <php> block, and in the .jsx file's leading comment. */
    const tally = (selector) => $(`.colocated-vue ${selector}`);
    await until('a co-located Vue component mounts', () => text(tally('.present')) === '0' && text(tally('.total')) === '0');
    click(window, tally('.here'));
    click(window, tally('.here'));
    click(window, tally('.away'));
    await until('its PHP methods run in the browser', () => text(tally('.present')) === '2' && text(tally('.absent')) === '1' && text(tally('.total')) === '3');
    click(window, tally('.take'));
    await until('its #[Server] method runs on the server', () => text(tally('.said')) === 'taken: 3');

    const greeting = (selector) => $(`.colocated-react ${selector}`);
    await until('a co-located React component mounts', () => greeting('.name').value === 'ada' && text(greeting('.shout')) === 'ADA!');
    reactType(greeting('.name'), 'x');
    click(window, greeting('.greet'));
    await until('its #[Validate] rule is checked on the server', () => text(greeting('.error')) === 'The name field must be at least 2 characters.');
    reactType(greeting('.name'), 'grace hopper');
    await until('its #[Computed] value, with Str (imported in the block), in the browser', () => text(greeting('.shout')) === 'GRACE HOPPER!');
    click(window, greeting('.greet'));
    await until('and its #[Server] method', () => text(greeting('.said')) === 'Hello, Grace Hopper (from the server)' && text(greeting('.error')) === '');

    /** Svelte: the PHP in the .svelte file's <php> block, as in a .vue file. */
    const score = (selector) => $(`.colocated-svelte ${selector}`);
    await until('a co-located Svelte component mounts', () => text(score('.score')) === '0' && text(score('.grade')) === 'fail');
    click(window, score('.add'));
    click(window, score('.add'));
    await until('its PHP methods and #[Computed] value run in the browser', () => text(score('.score')) === '60' && text(score('.grade')) === 'pass');
    click(window, score('.submit'));
    await until('its #[Server] method runs on the server, with Str from its block', () => text(score('.said')) === 'SUBMITTED 60');
    window.close();
});

/** With server rendering (nitro.ssr): the views in the first HTML, taken over by the browser rather than rendered again. */
if (ssr) await scenario('server rendering', async () => {
    const engines = ['vue', 'react', 'solid', 'svelte'];
    const html = await (await fetch(url + '/parity')).text();

    for (const engine of engines) {
        const element = new RegExp(`<div class="${engine}"><div [^>]*nitro:rendered>(.*?)</div></div>\\s*(<div class=|</section>)`, 's').exec(html);
        check(`${engine}: in the first HTML, from the state`, element !== null && /<span class="count">1<\/span>/.test(element[1]) && /<span class="tags">a<\/span>/.test(element[1]), element?.[1]?.slice(0, 200) ?? 'no nitro:rendered element');
    }

    /** The elements as the browser parsed them, before any script ran. */
    const window = await open('/parity', {
        beforeParse(window) {
            window.document.addEventListener('readystatechange', () => {
                if (window.document.readyState === 'interactive') window.parsed = engines.map((engine) => window.document.querySelector(`.${engine} .count`));
            });
        },
    });
    const { document } = window;
    const $ = (engine, selector) => document.querySelector(`.${engine} ${selector}`);

    for (const engine of engines) {
        click(window, $(engine, '.inc'));
        await until(`${engine}: hydrated: its handlers work`, () => text($(engine, '.count')) === '4' && text($(engine, '.doubled')) === '8');
    }

    check('hydrated, not rendered again: the elements the server sent are the ones in the page', engines.every((engine, i) => window.parsed?.[i] && window.parsed[i] === $(engine, '.count')), JSON.stringify(window.parsed?.map(Boolean)));
    window.close();
});

/** One component, its four views: the same actions on each, the same results (Components\Parity). */
await scenario('parity', async () => {
    const window = await open('/parity');
    const { document } = window;
    /** Typed as a keystroke types: through the native setter, so React sees the change too. */
    const typeIn = (el, value) => {
        el.focus();
        Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(el, value);
        el.dispatchEvent(new window.Event('input', { bubbles: true }));
    };
    const shown = (el) => el !== null && window.getComputedStyle(el).display !== 'none';
    /** Files chosen in an input (jsdom's inputs take none, so a list stands in), and its change. */
    const choose = (el, files) => {
        Object.defineProperty(el, 'files', { configurable: true, value: files });
        el.dispatchEvent(new window.Event('change', { bubbles: true }));
    };
    const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='), (c) => c.charCodeAt(0));
    const image = (name) => new window.File([png], name, { type: 'image/png' });

    for (const engine of ['blade', 'vue', 'react', 'solid', 'svelte']) {
        const $ = (selector) => document.querySelector(`.${engine} ${selector}`);
        const is = (label) => `${engine}: ${label}`;

        await until(is('mounts from the state: properties, #[Computed] values, an array'), () => text($('.count')) === '1' && text($('.doubled')) === '2' && text($('.tags')) === 'a');
        check(is('nothing unsaved yet'), !shown($('.unsaved')));

        click(window, $('.inc'));
        await until(is('a browser method: the state and its #[Computed] value follow'), () => text($('.count')) === '4' && text($('.doubled')) === '8');
        check(is('unsaved: changed here, not yet on the server'), shown($('.unsaved')));

        typeIn($('.email'), 'a@b.c');
        await until(is('a key under an array, written from an input'), () => text($('.email-shown')) === 'a@b.c');
        click(window, $('.tag'));
        await until(is('a method changing the array keeps what the input wrote in it'), () => text($('.tags')) === 'a,b' && $('.email').value === 'a@b.c');

        click(window, $('.save'));
        await until(is('a #[Server] call: processing, the button disabled'), () => shown($('.busy')) && $('.save').disabled);
        check(is('not slow at once'), !shown($('.slow')));
        await until(is('slow once it has taken 200ms'), () => shown($('.slow')));
        await until(is('refused by #[Validate]: the message, the call over'), () => text($('.error')) === 'The title field is required.' && !shown($('.busy')) && !shown($('.slow')) && !$('.save').disabled);
        check(is('nothing said'), text($('.said')) === '');

        typeIn($('.title'), 'Plan');
        click(window, $('.save'));
        await until(is('saved: what the server set, the error gone'), () => text($('.said')) === 'saved Plan at 4 for a@b.c' && text($('.error')) === '', 3000);        await until(is('nothing unsaved once the server has it'), () => !shown($('.unsaved')));

        /** Uploads: nitro:model on a file input in Blade, upload() in the others; the same results. */
        choose($('.photo'), [image(`${engine}.png`)]);
        await until(is('a file uploads into its property'), () => text($('.photo-name')) === `${engine}.png`);
        if (engine !== 'blade') check(is('uploads.photo follows it: done'), text($('.photo-progress')) === '100', text($('.photo-progress')));
        choose($('.photos'), [image('one.png'), image('two.png')]);
        await until(is('several, into an #[Uploads] list'), () => text($('.photos-count')) === '2');
        choose($('.photo'), [new window.File([new Uint8Array(1100 * 1024)], 'huge.png', { type: 'image/png' })]);
        await until(is('a file the rules refuse: the message, as validate() gives it'), () => /must not be greater than 1024 kilobytes/.test(text($('.photo-error')) ?? ''));
        check(is('and the property keeps what it had'), text($('.photo-name')) === `${engine}.png`, text($('.photo-name')));
        choose($('.photo'), [image('again.png')]);
        await until(is('a good file clears the error'), () => text($('.photo-error')) === '' && text($('.photo-name')) === 'again.png');

        /** A nitro:navigate link: written in Blade, link() in the others (their syntax can't always write the attribute). */
        check(is('a nitro:navigate link, its modifier on it'), $('.away')?.getAttribute('href') === '/counter' && $('.away').hasAttribute('nitro:navigate') && $('.away').hasAttribute('nitro:prefetch'), $('.away')?.outerHTML);

        /** Two fields filled at once, as autofill fills them: both reach the component. */
        typeIn($('.title'), 'Auto');
        typeIn($('.email'), 'auto@b.c');
        await until(is('two fields filled at once: the view keeps both'), () => $('.title').value === 'Auto' && $('.email').value === 'auto@b.c' && text($('.email-shown')) === 'auto@b.c');
        click(window, $('.save'));
        await until(is('and the server gets both'), () => text($('.said')) === 'saved Auto at 4 for auto@b.c', 3000);
    }

    check('the five are their own: each changed only itself', [...document.querySelectorAll('.parities .count')].map(text).join() === '4,4,4,4,4');

    /** link() from a React view: a visit, without a reload. */
    const runtime = window.Nitro;
    click(window, document.querySelector('.react .away'));
    await until('a link() link visits its page', () => window.location.pathname === '/counter' && byName(document, 'pages::counter') !== null);
    check('without a reload', window.Nitro === runtime);

    /**
     * A visit to a page of JavaScript views: the page shows with them rendered. The browser paints
     * between tasks only, so every view must be filled by the first task after the page went in.
     */
    const firstPaint = new Promise((resolve) => {
        const observer = new window.MutationObserver((mutations) => {
            if (!mutations.some((m) => [...m.addedNodes].some((n) => n.nodeType === 1 && n.hasAttribute('nitro:page')))) return;
            observer.disconnect();
            const empty = () => ['.vue-side', '.react-side', '.solid-side', '.vue-note-side', '.react-note-side', '.solid-note-side'].filter((side) => document.querySelector(`${side} [nitro\\:name]`)?.childElementCount === 0);
            const atInsert = empty();
            /** The next task: the first point the browser could paint. */
            window.setTimeout(() => resolve({ atInsert, atPaint: empty() }), 0);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    });
    window.Nitro.visit('/engines');
    const visit = await Promise.race([firstPaint, new Promise((resolve) => setTimeout(() => resolve(null), 5000))]);
    check('a visit: the page goes in with its views still to render', visit !== null && visit.atInsert.length > 0, JSON.stringify(visit));
    check('and they are rendered before the browser can paint: no empty frame, no shift', visit !== null && visit.atPaint.length === 0, JSON.stringify(visit));
    window.close();
});

/**
 * Events between components whatever their views (Components\Talk): each of Blade, Vue, React and
 * Solid sends from a browser method, from a #[Server] method, from its view, and to one component
 * (->to()); the others' #[On] methods hear it, and their views show it.
 */
await scenario('events between engines', async () => {
    const window = await open('/talk');
    const { document } = window;
    const engines = ['blade', 'vue', 'react', 'solid', 'svelte'];
    const next = { blade: 'vue', vue: 'react', react: 'solid', solid: 'svelte', svelte: 'blade' };
    const heard = (engine) => text(document.querySelector(`.talks .${engine} .heard`)) ?? '';
    const has = (engine, line) => heard(engine).split('; ').includes(line);

    await until('the five mount', () => engines.every((engine) => document.querySelector(`.talks .${engine} .shout`)));

    for (const from of engines) {
        const button = (name) => document.querySelector(`.talks .${from} .${name}`);
        const others = engines.filter((engine) => engine !== from);

        click(window, button('shout'));
        await until(`${from} shouts from a browser method: the others hear it`, () => others.every((engine) => has(engine, `shouted by ${from}`)));
        check(`${from} hears its own event too, as in Livewire`, has(from, `shouted by ${from}`), heard(from));

        click(window, button('announce'));
        await until(`${from} announces from a #[Server] method: the others hear it`, () => others.every((engine) => has(engine, `announced by ${from}`)));

        click(window, button('view-dispatch'));
        await until(`${from}'s view dispatches: the others' #[On] hear it`, () => others.every((engine) => has(engine, `shouted by ${from} view`)));

        click(window, button('whisper'));
        await until(`${from} whispers ->to(${next[from]}): it hears`, () => has(next[from], `whispered by ${from}`));
        await new Promise((resolve) => setTimeout(resolve, 150));
        check(`${from}'s whisper: no one else hears it`, engines.filter((engine) => engine !== next[from]).every((engine) => !has(engine, `whispered by ${from}`)), engines.map((engine) => `${engine}: ${heard(engine)}`).join(' | '));
    }

    window.close();
});

await scenario('browser APIs', async () => {
    const window = await open('/devices');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);

    /** The clipboard: write, then read back. */
    click(window, $('.copy'));
    await until('copied, then the callback ran', () => text($('.copied')) === 'copied');
    check('the clipboard has the text', window.clipboardText() === 'INV-0042', window.clipboardText());
    click(window, $('.paste'));
    await until('pasted', () => text($('.pasted')) === 'INV-0042');

    /** Storage: JSON values, kept in localStorage; a handler in the view calls it too. */
    click(window, $('.save'));
    await until('stored', () => text($('.stored')) === 'stored');
    check('as JSON', window.localStorage.getItem('draft') === '{"title":"Draft","at":1}', window.localStorage.getItem('draft'));
    click(window, $('.load'));
    await until('read back', () => text($('.loaded')) === 'Draft');
    check('session storage', window.sessionStorage.getItem('loads') === '1', window.sessionStorage.getItem('loads'));
    click(window, $('.forget'));
    await until('forgotten', () => window.localStorage.getItem('draft') === null);
    click(window, $('.load'));
    await until('the default when it is not there', () => text($('.loaded')) === 'none');
    check('session storage counts on', window.sessionStorage.getItem('loads') === '2');

    /** The canvas: drawn after the render, the calls in order, sized for the screen. */
    const canvas = $('canvas');
    await until('drawn on nitro:init', () => canvas.drawn?.length > 0);
    const drawn = () => canvas.drawn.map(([name, ...args]) => `${name}(${args.join(',')})`).join(' ');
    check('in order', drawn().includes('fillStyle(#4f46e5) fillRect(0,60,30,40) fillRect(40,30,30,70) fillRect(80,75,30,25) beginPath() arc(60,50,10,0,6.283185307179586) stroke()'), drawn());
    check('its size', canvas.style.width === '120px' && canvas.width === 120, `${canvas.style.width} ${canvas.width}`);
    canvas.drawn = [];
    click(window, $('.grow'));
    await until('drawn again', () => canvas.drawn.length > 0);
    check('with the new bar', drawn().includes('fillRect(120,10,30,90)'), drawn());

    /** A WebSocket: messages decoded, sends queued until it opens, one connection per URL, closed on leaving. */
    click(window, $('.connect'));
    await until('it opens', () => text($('.open')) === 'open');
    await until('its echo arrives', () => [...document.querySelectorAll('.messages li')].map(text).join(',') === 'hello');
    check('to the page\'s host, as ws:', window.sockets.length === 1 && window.sockets[0].url.startsWith('ws://') && window.sockets[0].url.endsWith('/echo'), window.sockets[0]?.url);
    click(window, $('.say'));
    await until('the same connection', () => [...document.querySelectorAll('.messages li')].map(text).join(',') === 'hello,again');
    check('one socket', window.sockets.length === 1);
    click(window, $('.disconnect'));
    await until('closed', () => text($('.open')) === 'closed');
    await wait(1100);
    check('and not reconnected', window.sockets.length === 1);
    window.close();
});

/**
 * Third-party libraries bound with the app's nitro: attributes (fixture resources/js/libraries.js):
 * select2 (one and a multiple, jQuery), Flatpickr, Tom Select, Choices and a widget that rebuilds
 * its own markup. Each starts once with the property's value; the visitor's choice reaches the
 * property; a change from code, from the server's answer, reaches the library; a render leaves it
 * alone; an @if and a new row start and stop theirs; a visit away tears every one down, and back
 * starts each once.
 */
/**
 * @nitroScript (fixture Sparkline, twice on /libraries, one in an @if): once for each instance as
 * it goes live, with $refs, $el, $nitro and $component; not again on a render; what it returns
 * runs as the instance leaves (an @if, a visit), and a new instance runs it again.
 */
await scenario('@nitroScript', async () => {
    const window = await open('/libraries');
    const { document } = window;
    const sparks = () => [...document.querySelectorAll('.spark')];
    const drawn = (i) => sparks()[i]?.querySelector('canvas').dataset.drawn;
    const runs = () => window.sparks?.runs ?? 0;
    const cleanups = () => window.sparks?.cleanups ?? 0;

    await until('it runs once for each instance', () => runs() === 2);
    check('$component.get(), $nitro and $el', drawn(0) === '[1,2]' && sparks()[0].querySelector('canvas').dataset.direct === '2:root', `${drawn(0)} ${sparks()[0].querySelector('canvas').dataset.direct}`);
    check('the second its own', drawn(1) === '[1,2]');

    click(window, sparks()[0].querySelector('.spark-add'));
    await until('$component.watch(): the change reaches it', () => drawn(0) === '[1,2,1]' && text(sparks()[0].querySelector('.spark-total')) === '4');
    check('the other instance untouched', drawn(1) === '[1,2]');
    check('a render runs it not again', runs() === 2, String(runs()));

    click(window, document.querySelector('.toggle'));
    await until('its instance leaves with the @if: what it returned runs', () => sparks().length === 1 && cleanups() === 1);
    click(window, document.querySelector('.toggle'));
    await until('a new instance runs it again', () => sparks().length === 2 && runs() === 3);
    await until('with its own state', () => drawn(1) === '[1,2]');

    click(window, document.querySelector('.away'));
    await until('a visit away: each one cleaned up', () => window.location.pathname === '/counter' && cleanups() === 3, 5000);
    window.close();
});

await scenario('third-party libraries', async () => {
    const window = await open('/libraries');
    const { document } = window;
    const $ = (selector) => document.querySelector(selector);
    const values = () => text($('.values'));
    const created = (name) => window.libraries.created[name] ?? 0;
    const destroyed = (name) => window.libraries.destroyed[name] ?? 0;
    const live = (name) => created(name) - destroyed(name);
    const select2s = () => document.querySelectorAll('select.select2-hidden-accessible').length;
    const s2 = (selector) => window.jQuery($(selector)).val();
    const picker = () => $('.fp')._flatpickr;
    const tom = () => $('.ts').tomselect;
    const choices = () => window.choicesOf.get($('.ch'));
    const fake = () => $('.fake .fake-option.on')?.dataset.value;
    const more = () => text($('.more'));
    const trixValue = () => $('.trix input[type=hidden]')?.value;
    const quill = () => window.quillOf.get($('.quill'));
    const sortable = () => window.sortableOf.get($('.sortable'));
    const sorted = () => [...document.querySelectorAll('.sortable li')].map((li) => li.dataset.id).join('');
    const slider = () => Math.round(Number($('.slider').noUiSlider.get()));
    const masked = () => $('.masked').value;
    const NEW = ['trix', 'quill', 'sortable', 'slider', 'mask'];

    await until('every library starts', () => created('select2') === 4 && created('flatpickr') === 1 && created('tom-select') === 1 && created('choices') === 1 && created('fake-widget') === 1, 5000);
    check('the page as the server rendered it', values() === 'b|x|2026-01-15|red|m|one|a|0', values());
    check('each library shows the property\'s value',
        s2('.s2') === 'b' && JSON.stringify(s2('.s2-multi')) === '["x"]' && $('.fp').value === '2026-01-15' && tom().getValue() === 'red' && choices().getValue(true) === 'm' && fake() === 'one' && s2('.s2-if') === 'b' && s2('.s2-row') === 'a',
        JSON.stringify([s2('.s2'), s2('.s2-multi'), $('.fp').value, tom().getValue(), choices().getValue(true), fake(), s2('.s2-if'), s2('.s2-row')]));
    check('one select2 each, its markup beside its select', select2s() === 4 && document.querySelectorAll('.select2-container').length === 4, String(select2s()));
    await until('Trix, Quill, SortableJS, noUiSlider and IMask start', () => NEW.every((name) => created(name) === 1));
    check('each with the property\'s value', more() === 'Hello|Hi|abc|30|1234123412341234' && trixValue() === '<div>Hello</div>' && quill().root.innerHTML === '<p>Hi</p>' && sorted() === 'abc' && slider() === 30 && masked() === '1234 1234 1234 1234',
        JSON.stringify([more(), trixValue(), quill().root.innerHTML, sorted(), slider(), masked()]));

    /** The visitor chooses, in each library: the property follows. */
    window.jQuery($('.s2')).val('c').trigger('change');
    await until('select2: the choice reaches the property', () => values().startsWith('c|'));
    await until('and the other select2 bound to it follows (watch)', () => s2('.s2-if') === 'c');
    window.jQuery($('.s2-multi')).val(['x', 'y']).trigger('change');
    await until('select2 multiple: an array', () => values().startsWith('c|x,y|'));
    picker().setDate('2026-03-03', true);
    await until('Flatpickr', () => values().includes('|2026-03-03|'));
    tom().setValue('blue');
    await until('Tom Select', () => values().includes('|blue|'));
    choices().setChoiceByValue('l');
    $('.ch').dispatchEvent(new window.CustomEvent('change', { bubbles: true }));
    await until('Choices', () => values().includes('|l|'));
    click(window, $('.fake .fake-option[data-value="two"]'));
    await until('a widget with events of its own', () => values().includes('|two|'));
    $('.trix trix-editor').editor.setSelectedRange(5);
    $('.trix trix-editor').editor.insertString(' world');
    await until('Trix: the typed text reaches the property', () => more().startsWith('Hello world|'));
    quill().insertText(quill().getLength() - 1, ' there', 'user');
    await until('Quill', () => more().includes('|Hi there|'));
    sortable().sort(['c', 'a', 'b'], false);
    sortable().options.onEnd({});
    await until('SortableJS: the new order', () => more().includes('|cab|'));
    $('.slider').noUiSlider.set(70);
    await until('noUiSlider', () => more().includes('|70|'));
    $('.masked').value = '5555666677778888';
    $('.masked').dispatchEvent(new window.Event('input', { bubbles: true }));
    await until('IMask: the digits, the input masked', () => more().endsWith('|5555666677778888') && masked() === '5555 6666 7777 8888');
    check('none of them started again', NEW.every((name) => created(name) === 1), JSON.stringify(window.libraries.created));
    check('no library started again', created('select2') === 4 && created('flatpickr') === 1 && created('tom-select') === 1 && created('choices') === 1 && created('fake-widget') === 1, JSON.stringify(window.libraries.created));

    /** A render of something else leaves each library as it is. */
    click(window, $('.bump'));
    await until('rendered', () => values().endsWith('|1'));
    check('after a render, each keeps its value and its markup',
        s2('.s2') === 'c' && $('.fp').value === '2026-03-03' && tom().getValue() === 'blue' && choices().getValue(true) === 'l' && fake() === 'two' && select2s() === 4,
        JSON.stringify([s2('.s2'), $('.fp').value, tom().getValue(), choices().getValue(true), fake(), select2s()]));
    check('and none started again', created('select2') === 4 && destroyed('select2') === 0, JSON.stringify(window.libraries));

    /** Code changes the properties, in the browser: each library follows, and says nothing back. */
    click(window, $('.local-reset'));
    await until('a browser method sets them', () => values().startsWith('a||2026-01-01|red|s|three|'));
    await until('each library shows the new value',
        () => s2('.s2') === 'a' && s2('.s2-if') === 'a' && JSON.stringify(s2('.s2-multi')) === '[]' && $('.fp').value === '2026-01-01' && tom().getValue() === 'red' && choices().getValue(true) === 's' && fake() === 'three');
    await until('and the others follow', () => more() === 'Reset|Reset|cba|10|1111222233334444' && trixValue() === '<div>Reset</div>' && quill().root.innerHTML === '<p>Reset</p>' && sorted() === 'cba' && slider() === 10 && masked() === '1111 2222 3333 4444');
    await wait(100);
    check('without a loop back (the values stay)', values().startsWith('a||2026-01-01|red|s|three|'), values());

    /** The server's answer changes them: each library follows, none starts again. */
    click(window, $('.server-reset'));
    await until('a #[Server] method sets them', () => values().startsWith('b|y,z|2026-02-02|green|m|two|'), 5000);
    await until('each library shows the server\'s value',
        () => s2('.s2') === 'b' && s2('.s2-if') === 'b' && JSON.stringify(s2('.s2-multi')) === '["y","z"]' && $('.fp').value === '2026-02-02' && tom().getValue() === 'green' && choices().getValue(true) === 'm' && fake() === 'two');
    await until('the others too', () => more() === 'Server|Server|bac|90|9999888877776666' && trixValue() === '<div>Server</div>' && quill().root.innerHTML === '<p>Server</p>' && sorted() === 'bac' && slider() === 90 && masked() === '9999 8888 7777 6666');
    check('a server call starts none again', created('select2') === 4 && created('flatpickr') === 1 && created('tom-select') === 1 && created('choices') === 1, JSON.stringify(window.libraries.created));

    /** An @if: its library is torn down with it, and starts again, once, with the value now. */
    click(window, $('.toggle'));
    await until('@if false: its select2 is torn down', () => $('.s2-if') === null && destroyed('select2') === 1);
    check('three left', select2s() === 3, String(select2s()));
    window.jQuery($('.s2')).val('c').trigger('change');
    await until('changed while it is gone', () => values().startsWith('c|'));
    click(window, $('.toggle'));
    await until('@if true: started again', () => $('.s2-if') !== null && created('select2') === 5);
    await until('with the value now', () => s2('.s2-if') === 'c');
    check('four again', select2s() === 4, String(select2s()));

    /** A new row of a list: its select2 starts, bound to its own row. */
    click(window, $('.add-row'));
    await until('a row\'s select2 starts', () => document.querySelectorAll('.s2-row').length === 2 && created('select2') === 6);
    const rowSelects = () => [...document.querySelectorAll('.s2-row')];
    check('with its row\'s value', window.jQuery(rowSelects()[1]).val() === 'c' && window.jQuery(rowSelects()[0]).val() === 'a');
    window.jQuery(rowSelects()[1]).val('b').trigger('change');
    await until('its choice reaches its row only', () => values().includes('|a,b|'));
    check('five, each beside its own select', select2s() === 5 && document.querySelectorAll('.select2-container').length === 5, String(select2s()));

    /** A visit away tears every one down; back, each starts once, with the page's values. */
    click(window, $('.away'));
    await until('away', () => window.location.pathname === '/counter' && $('.libraries') === null, 5000);
    await until('every library torn down', () => ['select2', 'flatpickr', 'tom-select', 'choices', 'fake-widget', ...NEW].every((name) => live(name) === 0));
    check('nothing of theirs left in the page', document.querySelectorAll('.select2-container, .flatpickr-calendar, .ts-wrapper, .choices').length === 0, String(document.querySelectorAll('.select2-container, .flatpickr-calendar, .ts-wrapper, .choices').length));
    window.Nitro.visit('/libraries');
    await until('back', () => $('.libraries') !== null, 5000);
    await until('each started once', () => live('select2') === 4 && live('flatpickr') === 1 && live('tom-select') === 1 && live('choices') === 1 && live('fake-widget') === 1 && NEW.every((name) => live(name) === 1), 5000);
    check('one editor, list and slider each', document.querySelectorAll('trix-editor').length === 1 && document.querySelectorAll('.ql-editor').length === 1 && document.querySelectorAll('.noUi-base').length === 1 && sorted() === 'abc', sorted());
    check('one each in the page', select2s() === 4 && document.querySelectorAll('.select2-container').length === 4 && document.querySelectorAll('.ts-wrapper').length === 1 && document.querySelectorAll('.choices').length === 1, String(select2s()));
    check('with the page\'s values', s2('.s2') === 'b' && $('.fp').value === '2026-01-15' && tom().getValue() === 'red' && choices().getValue(true) === 'm' && fake() === 'one',
        JSON.stringify([s2('.s2'), $('.fp').value, tom().getValue(), choices().getValue(true), fake()]));
    window.jQuery($('.s2')).val('a').trigger('change');
    await until('and bound as before', () => values().startsWith('a|'));

    /** Toggled fast, several times in one go: the @if's select2 ends as the last toggle says, once. */
    for (let i = 0; i < 5; i++) click(window, $('.toggle'));
    await until('five toggles: hidden', () => $('.s2-if') === null && live('select2') === 3);
    for (let i = 0; i < 3; i++) click(window, $('.toggle'));
    await until('three more: shown, once', () => $('.s2-if') !== null && live('select2') === 4 && select2s() === 4);

    /** The browser's back button: the page from the visits kept, each library once again. */
    click(window, $('.away'));
    await until('away again', () => window.location.pathname === '/counter' && live('select2') === 0, 5000);
    window.history.back();
    await until('back with the back button', () => $('.libraries') !== null, 5000);
    await until('each started once', () => live('select2') === 4 && live('flatpickr') === 1 && live('tom-select') === 1 && live('choices') === 1 && live('fake-widget') === 1, 5000);
    check('one each in the page, after back', select2s() === 4 && document.querySelectorAll('.ts-wrapper').length === 1 && document.querySelectorAll('.choices').length === 1, String(select2s()));
    window.close();
});

/**
 * The ways into a page with libraries that are not its first load: a visit from another page of the
 * same layout (which has the libraries already), and a visit from a page without them into a page
 * whose layout loads them, so that the libraries and their bindings arrive after Nitro started.
 */
await scenario('libraries after a visit in the same layout', async () => {
    const window = await open('/libraries-lobby');
    const { document } = window;
    const live = (name) => (window.libraries.created[name] ?? 0) - (window.libraries.destroyed[name] ?? 0);
    click(window, document.querySelector('.to-libraries'));
    await until('visited', () => document.querySelector('.libraries') !== null, 5000);
    await until('each library starts once', () => live('select2') === 4 && ['flatpickr', 'tom-select', 'choices', 'fake-widget', 'trix', 'quill', 'sortable', 'slider', 'mask'].every((name) => live(name) === 1), 5000);
    window.jQuery(document.querySelector('.s2')).val('c').trigger('change');
    await until('bound', () => text(document.querySelector('.values')).startsWith('c|'));
    window.close();
});

await scenario('libraries after a visit into a layout that loads them', async () => {
    const window = await open('/counter');
    const { document } = window;
    check('not loaded on this page', window.jQuery === undefined && window.libraries === undefined);
    window.Nitro.visit('/tools');
    await until('the page, in its layout', () => document.querySelector('.libraries') !== null && window.location.pathname === '/tools', 5000);
    const live = (name) => (window.libraries?.created[name] ?? 0) - (window.libraries?.destroyed[name] ?? 0);
    await until('the libraries load, their bindings register late, and each starts once on the page there', () => live('select2') === 4 && ['flatpickr', 'tom-select', 'choices', 'fake-widget', 'trix', 'quill', 'sortable', 'slider', 'mask'].every((name) => live(name) === 1), 8000);
    window.jQuery(document.querySelector('.s2')).val('a').trigger('change');
    await until('bound both ways', () => text(document.querySelector('.values')).startsWith('a|'));
    document.querySelector('.server-reset').click();
    await until('the server\'s answer reaches them', () => window.jQuery(document.querySelector('.s2')).val() === 'b' && text(document.querySelector('.more')).startsWith('Server|'), 5000);

    /** Away, and in again: the libraries are loaded once, the bindings registered once, each starts once. */
    window.Nitro.visit('/counter');
    await until('away', () => window.location.pathname === '/counter' && document.querySelector('.libraries') === null, 5000);
    await until('every library torn down', () => live('select2') === 0 && live('trix') === 0);
    window.Nitro.visit('/tools');
    await until('in again', () => document.querySelector('.libraries') !== null, 5000);
    await until('each starts once again', () => live('select2') === 4 && ['flatpickr', 'tom-select', 'choices', 'fake-widget', 'trix', 'quill', 'sortable', 'slider', 'mask'].every((name) => live(name) === 1), 5000);
    check('one select2 each', document.querySelectorAll('select.select2-hidden-accessible').length === 4);
    window.close();
});

// ------------------------------------------------------------------ helpers for the scenarios below

/** The window's requests that match, held until release(): answers that arrive late. */
function hold(window, matches) {
    const held = [];
    const fetch = window.fetch;
    window.fetch = (input, init) => {
        const target = new URL(input?.url ?? input, window.location.href);
        if (!matches(target, init)) return fetch(input, init);
        return new Promise((resolve, reject) => held.push(() => fetch(input, init).then(resolve, reject)));
    };
    return {
        get count() { return held.length; },
        release() { held.splice(0).forEach((send) => send()); },
    };
}

const button = (root, label) => [...root.querySelectorAll('button')].find((el) => text(el) === label);
const idle = (window) => window.Nitro.components().every((component) => !component.loading?.size);
const pages = { '/counter': 'pages::counter', '/board': 'pages::board', '/greeting': 'pages::greeting' };
/** The page shown is the one the address names. */
const showing = (window, path) => window.location.pathname === path && byName(window.document, pages[path]) !== null
    && Object.entries(pages).every(([other, name]) => other === path || byName(window.document, name) === null);

// ------------------------------------------------------------------ hooks
/** Each moment by its hook (Nitro.hook(), a plugin's on()) and its window event; the names before 2.x's hooks heard too. */
await scenario('hooks, and their earlier names', async () => {
    const heard = [];
    const window = await open('/counter', {
        beforeParse(window) {
            for (const event of ['nitro:init', 'nitro:starting']) window.document.addEventListener(event, (e) => heard.push(`${event}:${typeof e.detail?.Nitro?.hook}`));
        },
    });
    const { document } = window;
    const Nitro = window.Nitro;
    check('init, on the document, by both names, with Nitro', heard.includes('nitro:init:function') && heard.includes('nitro:starting:function'), heard.join(' '));

    const hooks = [];
    for (const name of ['component.initialized', 'component.rendered', 'beforeRequest', 'afterRequest', 'beforeNavigate', 'afterNavigate', 'error']) {
        Nitro.hook(name, (payload) => hooks.push({ name, payload }));
    }
    check('a late component.initialized listener hears the components already there', hooks.some((h) => h.name === 'component.initialized' && h.payload.name === N('pages::counter')), hooks.map((h) => h.name).join(' '));
    const plugin = [];
    Nitro.use(({ on }) => {
        for (const name of ['component', 'render', 'request', 'response', 'beforeRequest']) on(name, (payload) => plugin.push(name));
    });
    const events = [];
    for (const event of ['nitro:before-request', 'nitro:request', 'nitro:after-request', 'nitro:response', 'nitro:before-navigate', 'nitro:navigating', 'nitro:after-navigate', 'nitro:navigated']) {
        window.addEventListener(event, (e) => events.push(`${event}:${e.detail?.component ?? e.detail?.url ?? ''}`));
    }

    const counter = document.querySelector('.counter');
    click(window, button(counter, '+'));
    await until('a render', () => hooks.some((h) => h.name === 'component.rendered') && plugin.includes('render'));

    click(window, button(counter, 'Save'));
    await until('a #[Server] call, before and after', () => hooks.some((h) => h.name === 'afterRequest') && plugin.includes('response'), 5000);
    const before = hooks.find((h) => h.name === 'beforeRequest')?.payload;
    const after = hooks.find((h) => h.name === 'afterRequest')?.payload;
    check('beforeRequest: the component as plugins see it, the method, its kind, headers to add to', before?.component?.name === N('pages::counter') && before.method === M('save') && before.kind === 'call' && typeof before.headers === 'object', JSON.stringify(before));
    check('afterRequest: its status and timing', after?.kind === 'call' && after.status === 200 && typeof after.ms === 'number' && typeof after.bytes === 'number', JSON.stringify(after));
    check('a plugin hears both names, earlier and new', plugin.includes('request') && plugin.includes('beforeRequest'), plugin.join(' '));
    check('the window events, both names, with the component\'s name', ['nitro:before-request', 'nitro:request', 'nitro:after-request', 'nitro:response'].every((event) => events.includes(`${event}:${N('pages::counter')}`)), events.join(' '));

    /** A listener that fails is reported (error), and the others still run. */
    expectedErrors.push(/a failing listener/);
    const failing = Nitro.hook('component.rendered', () => {
        throw new Error('a failing listener');
    });
    const renders = hooks.filter((h) => h.name === 'component.rendered').length;
    click(window, button(counter, '+'));
    await until('reported as error', () => hooks.some((h) => h.name === 'error' && /a failing listener/.test(h.payload.message)));
    check('the other listeners still heard it', hooks.filter((h) => h.name === 'component.rendered').length > renders);
    failing();

    Nitro.visit('/board');
    await until('a visit, before and after', () => hooks.some((h) => h.name === 'afterNavigate'), 5000);
    check('the visit\'s hooks have its address', hooks.find((h) => h.name === 'beforeNavigate')?.payload.url.endsWith('/board') && hooks.find((h) => h.name === 'afterNavigate')?.payload.url.endsWith('/board'));
    check('its window events, both names', ['nitro:before-navigate', 'nitro:navigating', 'nitro:after-navigate', 'nitro:navigated'].every((event) => events.some((e) => e.startsWith(`${event}:`) && e.endsWith('/board'))), events.join(' '));

    check('an unknown hook is refused, naming the hooks', (() => {
        try {
            Nitro.hook('rendered', () => {});
            return false;
        } catch (error) {
            return /there is no hook "rendered" \(init, /.test(error.message);
        }
    })());
    window.close();
});

await scenario('hooks: initialized, a component\'s init and destroyed, a store updated', async () => {
    const heard = [];
    const window = await open('/embed', {
        beforeParse(window) {
            window.document.addEventListener('nitro:initialized', (e) => heard.push(`initialized:${e.detail.Nitro.components().length}`));
        },
    });
    const { document } = window;
    const Nitro = window.Nitro;
    check('initialized once, with the page\'s components live', heard.length === 1 && Number(heard[0].split(':')[1]) > 0, heard.join(' '));

    const moments = [];
    for (const name of ['component.init', 'component.initialized', 'component.destroyed']) Nitro.hook(name, (component) => moments.push(`${name}:${component.name}`));
    moments.length = 0;
    const list = byName(document, 'todo-list');
    type(window, list.querySelector('input[type=text]'), 'Hooked');
    list.querySelector('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    await until('a component made in the browser: init, then initialized', () => moments.includes(`component.initialized:${N('todo-item')}`), 5000);
    check('init before initialized', moments.indexOf(`component.init:${N('todo-item')}`) > -1 && moments.indexOf(`component.init:${N('todo-item')}`) < moments.indexOf(`component.initialized:${N('todo-item')}`), moments.join(' '));
    click(window, list.querySelectorAll('li.todo .remove')[0]);
    await until('removed by its view: destroyed', () => moments.includes(`component.destroyed:${N('todo-item')}`));
    moments.length = 0;
    list.remove();
    await until('taken out by a script: destroyed, children too', () => moments.includes(`component.destroyed:${N('todo-list')}`) && moments.includes(`component.destroyed:${N('todo-item')}`));
    window.close();

    const shop = await open('/shop');
    const updated = [];
    shop.Nitro.hook('store.updated', (store) => updated.push(store.name));
    click(shop, shop.document.querySelectorAll('.shop-shelf .buy')[0]);
    await until('a store changed: updated', () => updated.includes(N('stores::cart')), 3000);
    shop.close();
});

await scenario('hooks: stopping a request and a visit', async () => {
    const window = await open('/counter');
    const { document } = window;
    const Nitro = window.Nitro;
    const counter = document.querySelector('.counter');
    const save = button(counter, 'Save');
    const sent = requestsOf(window);
    const after = [];
    Nitro.hook('afterRequest', (request) => after.push(request));
    const saved = [];
    window.addEventListener('counter-saved', (event) => saved.push(event.detail.params));

    click(window, button(counter, '+'));
    await until('counted', () => text(counter.querySelector('.count')) === '1');
    const stop = Nitro.hook('beforeRequest', (request) => request.cancelable && request.preventDefault());
    click(window, save);
    await until('stopped: its afterRequest says so', () => after.some((request) => request.cancelled === true));
    await wait(100);
    check('nothing sent', !sent.some((path) => path.endsWith('/update')), sent.join(' '));
    check('nothing saved, the page usable', saved.length === 0 && !save.hasAttribute('disabled') && idle(window));
    stop();
    click(window, save);
    await until('the next call goes, with what changed meanwhile', () => saved.length === 1 && JSON.stringify(saved[0]) === '[1]', 5000);

    const visits = [];
    Nitro.hook('afterNavigate', (visit) => visits.push(visit));
    const hold = Nitro.hook('beforeNavigate', (visit) => visit.preventDefault());
    Nitro.visit('/board');
    await until('a visit stopped: its afterNavigate says so', () => visits.some((visit) => visit.cancelled === true));
    check('still on the page', showing(window, '/counter'), window.location.href);
    hold();
    Nitro.visit('/board');
    await until('the next visit goes', () => showing(window, '/board'), 5000);
    const backs = [];
    const refuse = Nitro.hook('beforeNavigate', (visit) => {
        backs.push(visit.cancelable);
        visit.preventDefault();
    });
    window.history.back();
    await until('back is not stopped: the address has moved', () => showing(window, '/counter'), 5000);
    check('and says it cannot be', backs.length > 0 && backs.every((cancelable) => cancelable === false), JSON.stringify(backs));
    refuse();
    window.close();

    /** An upload stopped: nothing sent, the input told. */
    const gallery = await open('/gallery');
    const xhr = [];
    const send = gallery.XMLHttpRequest.prototype.send;
    gallery.XMLHttpRequest.prototype.send = function (body) {
        xhr.push(1);
        return send.call(this, body);
    };
    gallery.Nitro.hook('beforeRequest', (request) => request.kind === 'upload' && request.preventDefault());
    const input = gallery.document.querySelector('input.photo');
    const told = [];
    input.addEventListener('nitro-upload-cancel', () => told.push('cancel'));
    Object.defineProperty(input, 'files', { configurable: true, value: [new gallery.File(['x'], 'kept.txt', { type: 'text/plain' })] });
    change(gallery, input);
    await until('the input told it was stopped', () => told.length === 1);
    check('nothing uploaded', xhr.length === 0 && gallery.document.querySelector('.preview') === null);
    gallery.close();
});

await scenario('the progress bar and back', async () => {
    const window = await open('/counter');
    const Nitro = window.Nitro;
    const afters = [];
    Nitro.hook('afterNavigate', (visit) => afters.push(visit));
    let busy = false;
    const watch = window.setInterval(() => (busy = busy || Nitro.progress.busy), 5);

    Nitro.visit('/board');
    await until('a visit', () => showing(window, '/board'), 5000);
    check('a link\'s visit shows the bar', busy === true);
    busy = false;
    window.history.back();
    await until('back', () => showing(window, '/counter'), 5000);
    await wait(100);
    check('back shows none', busy === false);

    /** Back while a visit is still loading: that visit is over, and so is its bar. */
    const held = hold(window, (target) => target.pathname === '/greeting');
    Nitro.visit('/greeting');
    await until('a visit under way', () => held.count === 1 && Nitro.progress.busy);
    window.history.forward();
    await until('forward meanwhile', () => showing(window, '/board'), 5000);
    check('the bar is done', !Nitro.progress.busy);
    check('the replaced visit had its afterNavigate, cancelled', afters.some((visit) => visit.url.endsWith('/greeting') && visit.cancelled === true), JSON.stringify(afters.map((visit) => [visit.url.slice(-9), visit.cancelled, visit.history])));
    held.release();
    await wait(300);
    check('and stays done when its answer comes', !Nitro.progress.busy && showing(window, '/board'));
    window.clearInterval(watch);
    window.close();
});

// ------------------------------------------------------------------ an unfriendly page
/**
 * What real pages do around Nitro: people clicking twice, leaving before an answer, visits that
 * overlap, other scripts moving or removing parts of the page, extensions adding their own markup,
 * a network that fails or a server that answers with an error page.
 */

await scenario('a double click on a #[Server] button', async () => {
    const window = await open('/counter');
    const { document } = window;
    const counter = document.querySelector('.counter');
    const save = button(counter, 'Save');
    const saved = [];
    window.addEventListener('counter-saved', (event) => saved.push(event.detail.params));

    click(window, button(counter, '+'));
    await until('counted', () => text(counter.querySelector('.count')) === '1');
    click(window, save);
    click(window, save);
    await until('the calls are answered', () => saved.length > 0 && idle(window), 5000);
    await wait(150);

    check('each answer saved what was shown', saved.every((params) => JSON.stringify(params) === '[1]'), JSON.stringify(saved));
    check('the count is the one saved', text(counter.querySelector('.count')) === '1', text(counter.querySelector('.count')));
    check('the button is usable again', !save.hasAttribute('disabled'));
    const ids = [...document.querySelectorAll('[nitro\\:id]')].map((el) => el.getAttribute('nitro:id'));
    check('no component twice', new Set(ids).size === ids.length, ids.join(' '));
    window.close();
});

await scenario('a #[Server] call answered after its page was left', async () => {
    const window = await open('/counter');
    const { document } = window;
    const held = hold(window, (target) => target.pathname.endsWith('/update'));

    click(window, button(document.querySelector('.counter'), 'Save'));
    await until('the call is on its way', () => held.count === 1);
    window.Nitro.visit('/board');
    await until('on the next page', () => showing(window, '/board'));
    const board = byName(document, 'pages::board');
    held.release();
    await wait(400);

    check('the late answer leaves the page shown as it is', showing(window, '/board') && byName(document, 'pages::board') === board, window.location.href);
    check('the page left has no component here', !window.Nitro.components().some((component) => component.name === N('pages::counter')), window.Nitro.components().map((component) => component.name).join(' '));
    window.close();
});

await scenario('a redirect answered after its page was left', async () => {
    const window = await open('/greeting');
    const { document } = window;
    const held = hold(window, (target) => target.pathname.endsWith('/update'));

    /** Its #[Server] method redirects to /counter, once answered. */
    click(window, document.querySelector('.celebrate-elsewhere'));
    await until('the call is on its way', () => held.count === 1);
    window.Nitro.visit('/board');
    await until('on another page meanwhile', () => showing(window, '/board'));
    held.release();
    await wait(500);

    check('the visitor stays where they went', showing(window, '/board'), window.location.href);
    window.close();
});

await scenario('an upload answered after its page was left', async () => {
    const window = await open('/gallery');
    const { document } = window;
    const gallery = window.Nitro.components().find((component) => component.name === N('pages::gallery'));
    /** Uploads go by XMLHttpRequest (it reports progress): held until let go. */
    const held = [];
    const send = window.XMLHttpRequest.prototype.send;
    window.XMLHttpRequest.prototype.send = function (body) {
        held.push(() => send.call(this, body));
    };
    const input = document.querySelector('input.photo');
    Object.defineProperty(input, 'files', { configurable: true, value: [new window.File(['x'], 'late.txt', { type: 'text/plain' })] });
    change(window, input);
    await until('the upload is on its way', () => held.length === 1);

    window.Nitro.visit('/board');
    await until('on another page meanwhile', () => showing(window, '/board'));
    held.splice(0).forEach((go) => go());
    await wait(500);

    check('the page shown is as it was', showing(window, '/board'));
    check('nothing set in the component that left', gallery.destroyed && gallery.data.get(P_('photo')) === null, String(gallery.data.get(P_('photo'))));
    window.close();
});

await scenario('two visits, the first answered last', async () => {
    const window = await open('/counter');
    const held = hold(window, (target) => target.pathname === '/board');

    window.Nitro.visit('/board');
    await until('the first visit is on its way', () => held.count === 1);
    window.Nitro.visit('/greeting');
    await until('the second arrives', () => showing(window, '/greeting'));
    held.release();
    await wait(400);

    check('the page is the last one asked for', showing(window, '/greeting'), window.location.href);
    window.history.back();
    await until('back: the page before them', () => showing(window, '/counter'));
    window.close();
});

await scenario('back while a visit is on its way', async () => {
    const window = await open('/counter');
    window.Nitro.visit('/greeting');
    await until('visited', () => showing(window, '/greeting'));
    const held = hold(window, (target) => target.pathname === '/board');

    window.Nitro.visit('/board');
    await until('the visit is on its way', () => held.count === 1);
    window.history.back();
    await until('back: the page before', () => showing(window, '/counter'));
    held.release();
    await wait(400);

    check('the late answer does not take the page over', showing(window, '/counter'), window.location.href);
    window.close();
});

await scenario('a script moves a component', async () => {
    const window = await open('/embed');
    const { document } = window;
    const list = byName(document, 'todo-list');
    /** As a modal library moves its content to the end of <body>. */
    const modal = document.createElement('div');
    modal.className = 'modal';
    document.body.append(modal);
    modal.append(list);

    type(window, list.querySelector('input[type=text]'), 'Moved');
    list.querySelector('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    await until('it renders where it now is', () => modal.querySelectorAll('li.todo').length === 3, 5000);
    click(window, button(list, 'Save all'));
    await wait(50);
    await until('its #[Server] call answers', () => idle(window), 5000);
    check('and it stays there, whole', list.parentNode === modal && modal.querySelectorAll('li.todo').length === 3, modal.innerHTML.slice(0, 300));
    window.close();
});

await scenario('a script removes a component', async () => {
    const window = await open('/embed');
    const { document } = window;
    const list = window.Nitro.components().find((component) => component.name === N('todo-list'));
    /** What its view shows, rendered still into its element though that is off the page. */
    const toggled = () => [...list.el.querySelectorAll('li.todo')].map((todo) => todo.classList.contains('done')).join(',');
    const before = toggled();

    /** As a script closing a dialog removes it. */
    list.el.remove();
    await wait(100);
    window.Nitro.dispatch('todo-toggled', [1]);
    await wait(100);

    check('a component taken off the page is let go', list.destroyed === true && !window.Nitro.components().includes(list), `destroyed: ${list.destroyed}`);
    check('so it no longer hears events', toggled() === before, `${before} -> ${toggled()}`);
    window.close();
});

await scenario('what a component had running, when a script takes it out', async () => {
    const window = await open('/clock');
    const clock = byName(window.document, 'clock');
    await until('it polls the server', () => Number(text(clock.querySelector('.synced'))) >= 1, 3000);
    await until('no call on its way', () => idle(window));
    const sent = requestsOf(window);
    clock.remove();
    await wait(700);
    check('its polls stop: nothing asked of the server', sent.length === 0, sent.join(' '));
    window.close();

    const live = await open('/live');
    const listening = () => [...live.echoChannels.values()].reduce((n, channel) => n + [...channel.listeners.values()].flat().length + channel.notifications.length, 0);
    await until('it listens to its channels', () => listening() > 0);
    byName(live.document, 'pages::live').remove();
    await wait(100);
    check('its broadcast listeners go with it', listening() === 0, String(listening()));
    live.close();
});

await scenario('markup an extension adds inside a component', async () => {
    const window = await open('/counter');
    const { document } = window;
    const counter = document.querySelector('.counter');
    /** As Grammarly and password managers add their elements and attributes. */
    counter.insertBefore(document.createElement('grammarly-extension'), counter.querySelector('ul'));
    counter.querySelector('input[type=number]').setAttribute('data-gramm', 'false');

    for (let i = 0; i < 6; i++) click(window, button(counter, '+'));
    await until('it renders as before', () => text(counter.querySelector('.count')) === '6' && text(counter.querySelector('strong')) === 'High!', 5000);
    check('its lists too', counter.querySelectorAll('ul li').length === 6 && counter.querySelectorAll('input.entry').length === 6, counter.innerHTML.slice(0, 400));
    check('one count, one heading', counter.querySelectorAll('.count').length === 1 && counter.querySelectorAll('strong').length === 1);
    window.close();
});

/** The window's requests to the server: what a test can check went out, or not. */
function requestsOf(window) {
    const sent = [];
    const fetch = window.fetch;
    window.fetch = (input, init) => {
        sent.push(new URL(input?.url ?? input, window.location.href).pathname);
        return fetch(input, init);
    };
    return sent;
}

await scenario('#[Url] with history: back and forward step through it', async () => {
    const window = await open('/tabs');
    const { document } = window;
    window.scrollTo = () => {};
    const shown = () => text(document.querySelector('.shown'));
    const at = (tab) => shown() === tab && (window.location.search === (tab === 'one' ? '' : `?tab=${tab}`));

    click(window, document.querySelector('.tab-two'));
    await until('a tab: its own entry', () => at('two'));
    click(window, document.querySelector('.tab-three'));
    await until('another', () => at('three'));
    window.history.back();
    await until('back: the tab before, shown', () => at('two'), 5000);
    window.history.back();
    await until('back again: the first', () => at('one'), 5000);
    window.history.forward();
    await until('forward', () => at('two'), 5000);
    window.close();

    /** The rest of the query is the page's or another script's: kept. */
    const other = await open('/tabs?ref=mail');
    click(other, other.document.querySelector('.tab-three'));
    await until('the tab in the address', () => new other.URL(other.location.href).searchParams.get('tab') === 'three');
    check('beside what was there', new other.URL(other.location.href).searchParams.get('ref') === 'mail', other.location.href);
    other.close();
});

await scenario('the address changed by another script', async () => {
    const window = await open('/counter?utm_source=mail');
    const { document } = window;
    const page = byName(document, 'pages::counter');
    window.scrollTo = () => {};
    /** As analytics does: the campaign parameters taken out of the address, in place. */
    window.history.replaceState(window.history.state, '', '/counter');
    const sent = requestsOf(window);
    const part = document.createElement('h2');
    part.id = 'later';
    part.scrollIntoView = () => {};
    document.body.append(part);
    const link = document.createElement('a');
    link.href = '#later';
    document.body.append(link);

    click(window, link);
    await until('a link to a part', () => window.location.hash === '#later');
    window.history.back();
    await until('back', () => window.location.hash === '');
    await wait(300);
    check('back between parts of the page asks nothing', sent.length === 0, sent.join(' '));
    check('the page is the one shown', byName(document, 'pages::counter') === page);
    window.close();
});

await scenario('history entries another script makes', async () => {
    const window = await open('/counter');
    const { document } = window;
    const page = byName(document, 'pages::counter');
    window.scrollTo = () => {};
    const sent = requestsOf(window);
    /** As a tabs library does: its own entry, with its own state, for the same page. */
    const heard = [];
    window.addEventListener('popstate', (event) => heard.push(event.state?.tab ?? null));
    window.history.pushState({ tab: 2 }, '', '/counter?tab=2');

    window.history.back();
    await until('back to the entry before it', () => window.location.search === '' && heard.length === 1);
    window.history.forward();
    await until('and forward to it', () => window.location.search === '?tab=2' && heard.length === 2);
    await wait(300);
    check('its state is its own', heard[1] === 2, JSON.stringify(heard));
    check('the page is the one shown, not asked for again', byName(document, 'pages::counter') === page && sent.length === 0, sent.join(' '));

    /** Nitro writes its own keys beside the script's. */
    window.dispatchEvent(new window.Event('scroll'));
    await wait(250);
    check('the script\'s state is kept beside Nitro\'s', window.history.state?.tab === 2, JSON.stringify(window.history.state));
    window.history.replaceState('a string', '', window.location.href);
    window.dispatchEvent(new window.Event('scroll'));
    await wait(250);
    check('a state that is not an object is left as it is', window.history.state === 'a string', JSON.stringify(window.history.state));
    window.close();
});

await scenario('what a script adds to rendered elements', async () => {
    /** Before Nitro starts, as a script at the end of <body> does. */
    const window = await open('/counter', {
        beforeParse(window) {
            window.document.addEventListener('nitro:starting', () => {
                const save = [...window.document.querySelectorAll('.counter button')].find((el) => el.textContent.trim() === 'Save');
                save?.setAttribute('data-track', 'save');
                save?.classList.add('tracked');
            });
        },
    });
    const { document } = window;
    const counter = document.querySelector('.counter');
    const save = button(counter, 'Save');
    check('what a script added before Nitro started is kept', save.getAttribute('data-track') === 'save' && save.classList.contains('tracked'), save.outerHTML);
    const count = counter.querySelector('.count');
    /** As a tooltip library, a theme switcher and an analytics script do. */
    count.classList.add('tipped');
    count.setAttribute('data-tip', 'The count');
    count.style.outline = '1px solid red';
    const badge = document.createElement('span');
    badge.className = 'badge';
    count.after(badge);

    click(window, button(counter, '+'));
    click(window, button(counter, '+'));
    await until('rendered again', () => text(count) === '2' && counter.querySelectorAll('ul li').length === 2);
    const items = [...counter.querySelectorAll('ul li')];
    items[0].classList.add('seen');
    click(window, button(counter, '+'));
    await until('and again: the last item moves on', () => counter.querySelectorAll('ul li').length === 3 && counter.querySelectorAll('ul li')[2].classList.contains('last'));

    check('the element is the same one', counter.querySelector('.count') === count);
    check('a class a script added stays', count.classList.contains('tipped') && count.classList.contains('count'), count.className);
    check('an attribute a script added stays', count.getAttribute('data-tip') === 'The count');
    check('a style a script set stays', count.style.outline === '1px solid red', count.getAttribute('style'));
    check('a node a script added stays', badge.isConnected && badge.previousElementSibling === count, counter.innerHTML.slice(0, 300));
    check('the view\'s own classes change beside a script\'s', items[0].classList.contains('seen') && !items[0].classList.contains('last') && items[1].classList.contains('even') && !items[1].classList.contains('last'), items.map((item) => item.className).join('|'));
    window.close();
});

await scenario('a network that fails, a server error page, an expired session', async () => {
    const window = await open('/counter');
    const { document } = window;
    const counter = document.querySelector('.counter');
    const save = button(counter, 'Save');
    const errors = [];
    window.addEventListener('nitro:error', (event) => errors.push(event.detail));
    const expired = [];
    window.addEventListener('nitro:expired', (event) => {
        expired.push(event.detail);
        event.preventDefault();
    });
    const serverFetch = window.fetch;
    const answering = (answer) => {
        window.fetch = (input, init) => (String(input?.url ?? input).includes('/update') ? answer() : serverFetch(input, init));
    };
    expectedErrors.push(new RegExp(`${M('save')}\\(\\): the server (could not be reached|answered (500|419))`));

    answering(() => Promise.reject(new TypeError('Failed to fetch')));
    click(window, save);
    await until('a network failure is reported', () => errors.length === 1 && idle(window));
    check('as one the server did not get', errors[0]?.status === 0, JSON.stringify(errors[0]));
    check('the button is usable again', !save.hasAttribute('disabled'));

    answering(async () => new Response('<h1>Server Error</h1>', { status: 500, headers: { 'Content-Type': 'text/html' } }));
    click(window, save);
    await until('an error page is reported', () => errors.length === 2 && idle(window));
    check('with its status', errors[1]?.status === 500, JSON.stringify(errors[1]));
    check('the page is as it was', document.querySelector('.counter') === counter && !save.hasAttribute('disabled'));

    answering(async () => new Response('', { status: 419 }));
    const runtime = window.Nitro;
    click(window, save);
    await until('an expired session is told to the page', () => expired.length === 1 && idle(window));
    check('which can keep it from loading again', window.Nitro === runtime && window.location.pathname === '/counter');

    window.fetch = serverFetch;
    const saved = [];
    window.addEventListener('counter-saved', (event) => saved.push(event.detail.params));
    click(window, button(counter, '+'));
    click(window, save);
    await until('the next call works', () => saved.length === 1 && JSON.stringify(saved[0]) === '[1]', 5000);
    window.close();
});

for (const line of log) {
    if (!/nitro:error|Not implemented/.test(line) && !expectedErrors.some((pattern) => pattern.test(line))) failures.push(`console.error: ${line}`);
}

process.stdout.write(JSON.stringify(failures));
