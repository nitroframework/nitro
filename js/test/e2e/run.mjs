// End-to-end: the fixture app served by `php -S`, pages loaded in jsdom with the real runtime and
// compiled components, driven like a user. stdin {url}; stdout a list of failed checks.
import { JSDOM, ResourceLoader, CookieJar, VirtualConsole } from 'jsdom';
import { readStdin } from '../support/common.mjs';

const { url } = await readStdin();
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

/** The browser's fetch, with the page's cookies (jsdom has none of its own). */
function browserFetch(window) {
    return async (input, init = {}) => {
        const target = new URL(input, window.location.href);
        const headers = new Headers(init.headers ?? {});
        const cookies = cookieJar.getCookieStringSync(target.href);
        if (cookies) headers.set('Cookie', cookies);
        headers.set('Sec-Fetch-Site', 'same-origin');
        const response = await fetch(target, { ...init, headers, redirect: 'follow' });
        for (const cookie of response.headers.getSetCookie?.() ?? []) cookieJar.setCookieSync(cookie, target.href);
        return response;
    };
}

async function open(path) {
    const virtualConsole = new VirtualConsole();
    virtualConsole.on('error', (...args) => log.push(args.map(String).join(' ')));
    virtualConsole.on('jsdomError', (error) => log.push(`jsdom: ${error.message}`));

    const dom = await JSDOM.fromURL(url + path, {
        runScripts: 'dangerously',
        resources: new ResourceLoader(),
        pretendToBeVisual: true,
        cookieJar,
        virtualConsole,
        beforeParse(window) {
            window.fetch = browserFetch(window);
            /** Browsers have these; jsdom does not. */
            window.TextEncoder = TextEncoder;
            window.TextDecoder = TextDecoder;
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

            /** Fusion::browser(): a clipboard, a canvas context that records its calls, and an echoing WebSocket. */
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
    });
    const { window } = dom;
    await until(`${path} loaded`, () => window.Fusion && window.document.readyState === 'complete' && window.Fusion.components().length > 0, 5000);
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
const byName = (root, name) => root.querySelector(`[fusion\:name="${N(name)}"]`);

async function scenario(name, run) {
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

    check('hydrated: handlers attached', $('button').hasAttribute('fusion:h-click'));
    check('hydrated: snapshot removed from the DOM', !counter.hasAttribute('fusion:snapshot'));
    check('hydrated: model shows the state', $('input[type=number]').value === '2', $('input[type=number]').value);

    /** From here on a change renders only what reads it: never all of it. */
    const component = window.Fusion.find(counter.getAttribute('fusion:id'));
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
    await until('fusion:model.number set the step', () => text($('.count')) === '3');

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
    await until('#[Server] save() round trip and its event', () => saved !== null);
    check('the event carried the server value', JSON.stringify(saved) === '[3]', JSON.stringify(saved));
    check('state kept after the round trip', text($('.count')) === '3');
    check('a snapshot with nothing new renders nothing again', full === 0, `${full} full renders`);
    check('fusion:loading.attr removed after the call', !save.hasAttribute('disabled'));

    /** Each @for button calls with its own $n, not the one the loop ended with. */
    const sets = counter.querySelectorAll('button.set');
    click(window, sets[1]);
    await until('the second @for button sets 20', () => text($('.count')) === '20');
    click(window, sets[0]);
    await until('the first @for button sets 10', () => text($('.count')) === '10');

    /** An answer that is not Fusion's (PHP printing a warning, say): fusion:error, not a crash. */
    const serverFetch = window.fetch;
    window.fetch = async () => new Response('<br /><b>Warning</b>: POST data can\'t be buffered', { status: 200 });
    let error = null;
    window.addEventListener('fusion:error', (e) => (error = e.detail), { once: true });
    click(window, save);
    await until('fusion:error for an answer that is not JSON', () => error !== null);
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
    check('the new child is a component', added.hasAttribute('fusion:id') && window.Fusion.find(added.getAttribute('fusion:id')) !== null);
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
    check('removed child component forgotten', window.Fusion.components().filter((c) => c.name === N('todo-item')).length === 2);

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
    const page = document.querySelector('[fusion\\:page]');
    /** A chunk's script: by the route (/_fusion/app/<id>.js) or the build's static file (<id>-<version>.js). */
    const chunk = (group) => document.querySelector(`script[src*="/app/${chunkId(group)}.js"], script[src*="/${chunkId(group)}-"]`);

    /** #[LazyLoad]: the page's chunk came with the page; the others wait until a page needs them. */
    check('the page\'s chunk is listed by @fusionScripts', chunk('pages') !== null && window.Fusion.definitions.has(N('pages::student-page')));
    check('other chunks are not loaded', chunk('board') === null && !window.Fusion.definitions.has(N('pages::board')) && !window.Fusion.definitions.has(N('todo-item')));

    type(window, page.querySelector('input'), 'hello');
    await until('model re-renders the page', () => text(page.querySelector('.note')) === 'Note: hello');

    click(window, page.querySelector('button'));
    await until('#[Server] rename() updated the model', () => text(page.querySelector('h1')) === 'Renamed');
    check('a method on model data in the browser', text(page.querySelector('.shout')) === 'RENAMED!', text(page.querySelector('.shout')));
    check('browser state kept through the call', text(page.querySelector('.note')) === 'Note: hello');

    const link = document.querySelector('a[fusion\\:navigate]');
    check('fusion:current off elsewhere', !link.classList.contains('active'));
    click(window, link);
    await until('navigated without reloading', () => window.location.pathname === '/counter' && byName(document, 'pages::counter') !== null);
    check('title followed', document.title === 'Fusion', document.title);
    check('fusion:current on', link.classList.contains('active'));
    check('the old page\'s component is gone', !window.Fusion.components().some((c) => c.name === N('pages::student-page')));

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
    await until('navigated to the board', () => window.location.pathname === '/board' && document.querySelector('[fusion\\:name="todo-list"] li.todo') !== null);
    await until('the progress bar completes and goes', () => document.querySelector('#nprogress') === null);
    check('the bar has the configured colour and height', [...document.querySelectorAll('style')].some((s) => /#nprogress \{[^}]*height: 4px;/.test(s.textContent) && /#nprogress \.bar \{ background: #e11d48;/.test(s.textContent)));    window.fetch = pageFetch;
    check('the visit got JSON', answers.length === 1 && /application\/json/.test(answers[0]), answers.join(', '));
    check('board title followed', document.title === 'Board', document.title);

    const board = document.querySelector('[fusion\\:page]');
    check('the page element is the board', board.getAttribute('fusion:name') === N('pages::board') && text(board.querySelector('h2')) === 'Board');
    check('the page element names its layout', board.getAttribute('fusion:page') === 'components.layouts.app');
    const list = byName(board, 'todo-list');
    check('the child with mount() came from the server', list !== null && window.Fusion.find(list.getAttribute('fusion:id'))?.snapshot !== null);
    check('its own children were made in the browser', list.querySelectorAll('li.todo').length === 2, String(list.querySelectorAll('li.todo').length));
    check('no placeholders left', board.querySelector('[fusion\\:placeholder]') === null);
    check('the children made in the browser loaded their chunk', chunk('todos') !== null && window.Fusion.definitions.has(N('todo-item')));

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
    check('only the board\'s components are alive', window.Fusion.components().every((c) => ['pages::board', 'todo-list', 'todo-item'].map(N).includes(c.name)), window.Fusion.components().map((c) => c.name).join(','));

    /** fusion:navigate.keypress: the visit starts as the button goes down; its click is the same visit. */
    const pointer = (type, el, pointerType) => {
        const event = new window.MouseEvent(type, { bubbles: true, cancelable: true, button: 0 });
        Object.defineProperty(event, 'pointerType', { value: pointerType }); // jsdom has no PointerEvent
        el.dispatchEvent(event);
    };
    let visits = 0;
    window.addEventListener('fusion:navigating', () => visits++);
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

// ------------------------------------------------------------------ fusion:poll, fusion:init, fusion:confirm, #[Computed]
await scenario('clock', async () => {
    const window = await open('/clock');
    const { document } = window;
    const clock = byName(document, 'clock');
    const $ = (selector) => clock.querySelector(selector);
    const ticks = () => parseInt(text($('.label')), 10);

    await until('fusion:init ran once the element appeared', () => $('.ready') !== null);
    await until('fusion:poll calls a browser method', () => ticks() >= 3);
    check('#[Computed] read twice in a render gives the same value', text($('.label')) === text($('.again')), `${text($('.label'))} / ${text($('.again'))}`);
    await until('fusion:poll calls a #[Server] method too', () => Number(text($('.synced'))) >= 2);

    click(window, $('.stop'));
    await wait(150);
    const stopped = ticks();
    await wait(350);
    check('a poll stops when its element leaves', ticks() === stopped, `${stopped} then ${ticks()}`);

    /** #[Computed]: worked out once until the state changes. */
    const component = window.Fusion.find(clock.getAttribute('fusion:id'));
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

    /** fusion:confirm asks first; a no leaves the state alone. */
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

    check('the page ships what the helpers name', window.Fusion.B.globals.routes['students.show']?.uri === 'students/{student}' && window.Fusion.B.globals.config['app.name'] !== undefined);
    const root = window.location.origin;
    check('hydrated as the server rendered it', href('next') === root + '/students/2?from=ada%20lovelace', href('next'));

    click(window, links.querySelector('button'));
    await until('route() in a browser method follows the state', () => href('next') === root + '/students/3?from=grace%20hopper');
    check('route() in the template too', href('named') === root + '/students/2?tab=notes', href('named'));
    check('__() with its replacements', text(links.querySelector('a.named')) === "Open Grace hopper's record" && text(links.querySelector('.welcome')) === 'Welcome, GRACE HOPPER! (grace hopper, Grace hopper)', text(links.querySelector('.welcome')));
    check('the other helpers', links.querySelector('img').getAttribute('src') === root + '/img/logo.png' && links.querySelector('img').getAttribute('alt') === 'Fusion Tests' && href('relative') === '/counter?page=2');

    window.close();
});

// ------------------------------------------------------------------ lifecycle hooks, $this->js(), fusion:ref
await scenario('lifecycle', async () => {
    const window = await open('/journal');
    const { document } = window;
    const journal = byName(document, 'pages::journal');
    const note = journal.querySelector('input');

    /** @teleport: the server rendered the content at its @portal (Viewi's portals); the browser hydrated it there. */
    const docks = () => [...document.querySelectorAll('.journal-dock')];
    check('@teleport content is at its @portal, once', docks().length === 1 && docks()[0].parentElement.id === 'dock' && docks()[0].fusionOwner !== undefined && text(docks()[0].querySelector('.dock-note')) === 'first entry', document.body.innerHTML);
    check('nothing of it in place', journal.querySelector('aside, template') === null, journal.innerHTML);
    check('the portal marks and the page around them kept', document.getElementById('portal_dock_end') !== null && document.getElementById('portal_toasts_end') !== null && document.querySelector('nav') !== null, document.body.innerHTML);

    await until('mounted() ran in the browser', () => text(journal.querySelector('.status')) === 'ready, 0 saved');
    await until('its $this->js() used $refs', () => document.activeElement === note);
    await until('a @teleport in an @if came with it', () => document.querySelector('#toasts .journal-toast') !== null);

    const toastNote = document.querySelector('#toasts .toast-note');
    type(window, toastNote, 'from the toast');
    await until('fusion:model in teleported content sets the component\'s state', () => note.value === 'from the toast' && text(docks()[0].querySelector('.dock-note')) === 'from the toast');

    type(window, note, 'hello');
    await until('and shows it', () => toastNote.value === 'hello');
    click(window, journal.querySelector('button'));
    await until('$this->js() from a #[Server] method ran after the render', () => window.savedJournal !== undefined);
    check('with $fusion, $refs and $el', window.savedJournal === 'hello / ready, 1 saved / SECTION', window.savedJournal);
    await until('teleported content renders its @foreach', () => text(docks()[0].querySelector('em')) === 'hello');

    click(window, document.querySelector('#toasts .dismiss'));
    await until('fusion:click in teleported content runs on its component; the @if took the content away', () => text(journal.querySelector('.status')) === 'waiting, 1 saved' && document.querySelector('#toasts .journal-toast') === null);

    let gone = null;
    window.addEventListener('journal-gone', (e) => (gone = e.detail.params));
    click(window, journal.querySelector('a.away'));
    await until('navigated away', () => window.location.pathname === '/counter');
    await until('destroyed() ran as it left', () => gone !== null);
    check('with its last state', JSON.stringify(gone) === '["hello"]', JSON.stringify(gone));
    check('its teleported content left with it', document.querySelector('.journal-dock') === null, document.body.innerHTML);

    window.close();
});

// ------------------------------------------------------------------ slots
await scenario('slots', async () => {
    const window = await open('/shelf');
    const { document } = window;
    const [stock, empty] = document.querySelectorAll('[fusion\\:name="card"]');
    const count = () => text(stock.querySelector('.count'));
    const items = () => [...stock.querySelectorAll('.body > ul > li')].map(text).join(',');

    check('hydrated: the parent\'s content in the card', count() === 'Books: 1' && items() === 'Atlas,Bible', `${count()} ${items()}`);
    check('the empty card: its fallback, no footer', !empty.querySelector('footer') && text(empty.querySelector('.blank')) === 'Nothing here yet.' && !stock.querySelector('.blank'));
    check('the child in the slot is live', window.Fusion.find(stock.querySelector('li.todo')?.getAttribute('fusion:id') ?? '') !== null);

    click(window, stock.querySelector('footer .add'));
    await until('the parent\'s handler in a named slot, and its content in the card followed', () => count() === 'Books: 2' && items() === 'Atlas,Bible,Item 2');

    type(window, stock.querySelector('input.label'), 'Maps');
    await until('the parent\'s field in the card', () => count() === 'Maps: 2');
    await until('the slot\'s own child took the parent\'s value', () => text(stock.querySelector('li.todo span')) === 'Maps');
    check('the parent\'s state, not the card\'s', window.Fusion.components().find((c) => c.name === N('pages::shelf')).data.get(P_('label')) === 'Maps');

    click(window, stock.querySelector('.toggle'));
    await until('the card hid its body (its own state)', () => stock.querySelector('.body') === null);
    click(window, stock.querySelector('.toggle'));
    await until('and showed it again', () => stock.querySelector('.body') !== null);
    check('rendered from the parent, as it is now', count() === 'Maps: 2' && items() === 'Atlas,Bible,Item 2', `${count()} ${items()}`);
    click(window, stock.querySelector('footer .add'));
    await until('and still the parent\'s, live', () => count() === 'Maps: 3' && items() === 'Atlas,Bible,Item 2,Item 3');

    window.close();
});

// ------------------------------------------------------------------ events and fusion:model on a child (Livewire's ways)
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
    await until('the parent\'s change reaches the child', () => input.value === 'reset' && window.Fusion.find(box.getAttribute('fusion:id')).data.get(P_('value')) === 'reset');
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
    const current = () => panels.querySelector('.current [fusion\:name]');
    const ids = () => window.Fusion.components().map((c) => c.id);

    check('<fusion:dynamic-component> renders the component :is names', current()?.getAttribute('fusion:name') === N('card'), panels.innerHTML);
    check('in a loop, each its own', [...panels.querySelectorAll('li > [fusion\:name]')].map((el) => el.getAttribute('fusion:name')).join(',') === ['card', 'search-box'].map(N).join(','), panels.innerHTML);
    const card = current();
    const cardId = card.getAttribute('fusion:id');
    check('keyed with its name', cardId.endsWith('.c0:card'), cardId);

    click(window, card.querySelector('.toggle'));
    await until('the card has state', () => text(current().querySelector('.toggle')) === 'Show');

    click(window, panels.querySelector('.to-search'));
    await until('another name renders that component instead', () => current()?.getAttribute('fusion:name') === N('search-box'));
    check('the one before left the page, and the runtime', !card.isConnected && !ids().includes(cardId), ids().join(' '));

    click(window, panels.querySelector('.to-none'));
    await until('"" renders none', () => current() === null && panels.querySelector('.current').children.length === 0);

    click(window, panels.querySelector('.to-card'));
    await until('back to the card: a new one', () => current()?.getAttribute('fusion:name') === N('card'));
    check('with its own state (not the old one\'s)', text(current().querySelector('.toggle')) === 'Hide', current().outerHTML);

    window.close();
});

// ------------------------------------------------------------------ Livewire and Fusion on one page
await scenario('livewire', async () => {
    const window = await open('/both');
    const { document } = window;
    await until('Livewire started', () => window.Livewire !== undefined && document.querySelector('.ticker') !== null);

    const counter = byName(document, 'counter');
    click(window, counter.querySelectorAll('button')[1]);
    await until('the Fusion component works next to Livewire', () => text(counter.querySelector('.count')) === '1');

    click(window, document.querySelector('.ticker button'));
    await until('the Livewire component works next to Fusion', () => text(document.querySelector('.ticks')) === '1', 5000);

    check('Fusion kept its state through Livewire\'s update', text(counter.querySelector('.count')) === '1');
    check('Livewire did not pick up Fusion\'s component', !counter.hasAttribute('wire:id'));
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
    for (const name of ['fusion-upload-start', 'fusion-upload-progress', 'fusion-upload-finish', 'fusion-upload-error']) {
        input.addEventListener(name, (e) => events.push(name + (e.detail?.status ? `:${e.detail.status}` : '')));
    }

    choose(input, [new window.File([png], 'Birthday Cake.png', { type: 'image/png' })]);
    check('the loading state shows while it uploads', gallery.querySelector('.uploading').hasAttribute('data-fusion-loading'));
    await until('the preview shows', () => /\/_fusion\/upload\/preview\?t=/.test(gallery.querySelector('.preview img')?.getAttribute('src') ?? ''));
    check('what the view shows of it', text(gallery.querySelector('figcaption')) === 'Birthday Cake.png (png, image/png, 68 bytes)', text(gallery.querySelector('figcaption')));
    check('start and finish on the input', events.includes('fusion-upload-start') && events.includes('fusion-upload-finish'), events.join(', '));
    check('the loading state is gone', !gallery.querySelector('.uploading').hasAttribute('data-fusion-loading'));

    const preview = await fetch(new URL(gallery.querySelector('.preview img').getAttribute('src'), url));
    check('the preview is the image', preview.status === 200 && preview.headers.get('content-type') === 'image/png', `${preview.status} ${preview.headers.get('content-type')}`);

    /** Several, for the array property. */
    choose(gallery.querySelector('input.photos'), [new window.File([png], 'one.png', { type: 'image/png' }), new window.File(['hello'], 'notes.txt', { type: 'text/plain' })]);
    await until('the list shows both', () => text(gallery.querySelector('.count')) === '2 photos');
    check('their names', text(gallery.querySelector('ul.photos')) === 'one.png notes.txt', text(gallery.querySelector('ul.photos')));

    click(window, gallery.querySelector('.save'));
    await until('save() stored the photo', () => text(gallery.querySelector('.stored')) === 'gallery/cover.png');
    check('the property is empty again', gallery.querySelector('.preview') === null && text(gallery.querySelector('.count')) === '0 photos');
    check('so is the input', input.value === '');

    /** Too big for fusion.uploads.rules (max 1024 KB): the message, as validate() gives it. */
    choose(input, [new window.File([new Uint8Array(1100 * 1024)], 'huge.png', { type: 'image/png' })]);
    await until('the error shows', () => /must not be greater than 1024 kilobytes/.test(text(gallery.querySelector('.error')) ?? ''));
    check('an error event', events.includes('fusion-upload-error:422'), events.join(', '));
    check('no photo', gallery.querySelector('.preview') === null);

    choose(input, [new window.File([png], 'again.png', { type: 'image/png' })]);
    await until('a good file clears the error', () => gallery.querySelector('.error') === null && text(gallery.querySelector('figcaption'))?.startsWith('again.png'));
    window.close();
});

// ------------------------------------------------------------------ Blade components and @include (/showcase)
await scenario('inlined views', async () => {
    const window = await open('/showcase');
    const { document } = window;
    const showcase = document.querySelector('.showcase');
    const [clickButton, featureButton] = showcase.querySelectorAll('button');

    /** fusion:click given to <x-button>: the Fusion component's method. */
    click(window, clickButton);
    await until('the count follows', () => text(showcase.querySelector('.clicks')) === '1');
    check('the card\'s named slot follows', text(showcase.querySelector('.foot')) === '1 clicks', text(showcase.querySelector('.foot')));

    click(window, clickButton);
    click(window, clickButton);
    await until('three clicks', () => text(showcase.querySelector('.clicks')) === '3');
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
    window.close();
});

await scenario('render and shared', async () => {
    const window = await open('/greeting');
    const { document } = window;
    const greeting = () => document.querySelector('.greeting');

    /** Fusion::share()'s data, rendered in the browser as on the server. */
    check('the shared name after hydration', text(greeting().querySelector('.hello')) === 'Hello Ada', text(greeting().querySelector('.hello')));
    check('a missing key gives the default', text(greeting().querySelector('.missing')) === 'fallback');

    /** $this->shared() in a browser method. */
    click(window, greeting().querySelector('.shout'));
    await until('the browser method reads the shared data', () => text(greeting().querySelector('.shouted')) === 'ADA');

    /** A #[Server] call that changes the shared data: its answer re-renders with the new data. */
    click(window, greeting().querySelector('.rename'));
    await until('the shared name follows the call', () => text(greeting().querySelector('.hello')) === 'Hello Grace');

    /** fusion:navigate to a page a controller returned with Fusion::render(). */
    click(window, greeting().querySelector('.to-counter'));
    await until('the rendered page is shown in place', () => window.location.pathname === '/rendered/counter' && text(document.querySelector('.count')) === '7');
    check('the greeting is gone', greeting() === null);
    check('the page was not reloaded', window.Fusion !== undefined);
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
    await until('the scroll position is kept in the history entry', () => window.history.state?.fusionScroll?.y === 420);

    click(window, document.querySelector('.to-counter'));
    await until('a visit goes to the top', () => window.location.pathname === '/rendered/counter' && scrolls.at(-1) === '0,0');

    window.history.back();
    await until('back restores the position', () => window.location.pathname === '/greeting' && document.querySelector('.greeting') !== null && scrolls.at(-1) === '0,420');

    const before = scrolls.length;
    click(window, document.querySelector('.to-counter-kept'));
    await until('a preserve-scroll visit lands', () => window.location.pathname === '/rendered/counter');
    check('fusion:navigate.preserve-scroll keeps the position', scrolls.length === before, scrolls.join(' '));
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
    stale.Fusion.config.bundle = 'an-earlier-build';
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
        if (new Headers(init.headers ?? {}).has('X-Fusion-Partial')) await held;
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
    const window = await open('/rendered/orders');
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
    await until('another page', () => window.location.pathname === '/rendered/counter' && document.querySelector('.count') !== null);

    window.history.back();
    await until('back brings the #[Remember] property back', () => window.location.pathname === '/greeting' && draft().join('|') === 'half done|half done');
    check('a property that is not remembered starts afresh', text(document.querySelector('.shouted')) === '', text(document.querySelector('.shouted')));

    type(window, document.querySelector('.draft'), 'changed');
    await until('a later change', () => draft()[1] === 'changed');
    window.history.forward();
    await until('forward', () => window.location.pathname === '/rendered/counter' && document.querySelector('.count') !== null);
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

    check('fusion:visible.200px observes with its margin', window.observers.some((o) => o.options.rootMargin === '200px' && o.elements.has(document.querySelector('.more'))));
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
        sentOnce.push(new Headers(init.headers ?? {}).get('X-Fusion-Once'));
        return fetch(input, init);
    };
    const flashes = [];
    window.addEventListener('fusion:flash', (e) => flashes.push(e.detail.flash));

    check('once data on the page', text(document.querySelector('.countries')) === '2 countries', text(document.querySelector('.countries')));
    click(window, document.querySelector('.celebrate'));
    await until('flash data from a #[Server] call', () => text(document.querySelector('.toast')) === 'Well done');
    check('fusion:flash announces it', flashes.some((flash) => flash.toast === 'Well done'), JSON.stringify(flashes));
    check('a #[Server] call says the browser has the once data', sentOnce.at(-1) === 'countries', String(sentOnce));

    click(window, document.querySelector('.to-counter'));
    await until('a visit', () => window.location.pathname === '/rendered/counter' && document.querySelector('.count') !== null);
    check('so does a visit', sentOnce.at(-1) === 'countries', String(sentOnce));

    window.history.back();
    await until('back', () => window.location.pathname === '/greeting' && document.querySelector('.greeting') !== null);
    check('the once data is kept though the server left it out', text(document.querySelector('.countries')) === '2 countries', text(document.querySelector('.countries')));
    check('flash data belongs to the page it came with', text(document.querySelector('.toast')) === 'no toast', text(document.querySelector('.toast')));

    click(window, document.querySelector('.celebrate-elsewhere'));
    await until('a call that redirects: its flash data comes with the page it goes to', () => window.location.pathname === '/rendered/counter' && window.Fusion.B.flashed('toast') === 'Over here');
    window.close();

    const timeline = await open('/rendered/timeline');
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

    click(window, $('.toggle'));
    await wait(30);
    check('leaving, it stays until its animation ends', $('.panel') !== null && $('.panel').style.opacity === '0', String($('.panel')?.getAttribute('style')));
    await until('then it goes', () => $('.panel') === null, 1000);

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
    await until('drawn on fusion:init', () => canvas.drawn?.length > 0);
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

for (const line of log) {
    if (!/fusion:error|Not implemented/.test(line) && !expectedErrors.some((pattern) => pattern.test(line))) failures.push(`console.error: ${line}`);
}

process.stdout.write(JSON.stringify(failures));
