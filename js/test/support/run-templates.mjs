// Template parity: stdin {bundle, cases: [{id, name, componentId, state, errors, ssr, next?}]}.
// Each component's compiled template is rendered to DOM in Node (runtime/render.js); that DOM
// must be the one the browser makes of the server's HTML. A case with next ({state, errors, ssr,
// changed}) then changes to that state: the view updates what reads what changed, and must be the
// server's HTML of the new state.
import { JSDOM } from 'jsdom';
import * as P from '../../src/php.js';
import * as B from '../../src/laravel/blade.js';
import { ErrorBag } from '../../src/runtime/component/errors.js';
import { View } from '../../src/runtime/view/view.js';
import { defineView } from '../../src/runtime/view/calls.js';
import { enumCase } from '../../src/runtime/component/context.js';
import { readStdin } from './common.mjs';

const input = await readStdin();
const definitions = {};
const stores = {};
const Nitro = { define: (name, d) => (definitions[name] = d), store: (name, d) => (stores[name] = d), view: defineView, lazy: () => {}, markupPages: () => {}, classes: Object.create(null), P, B };
new Function('window', input.bundle)({ Nitro });

/** A store as a view reads it: its properties, its methods and #[Computed] values (read only while rendering). */
function storeContext(definition, state) {
    const data = P.decode(state);
    const ctx = Object.create(null);
    for (const name of Object.keys(definition.props)) {
        Object.defineProperty(ctx, name, { get: () => data.get(name) ?? null, set: () => { throw new Error('render wrote a store'); } });
    }
    Object.assign(ctx, definition.methods);
    ctx.$computed = (name) => ctx[name]();
    return ctx;
}

const { document } = new JSDOM('<!DOCTYPE html>').window;
B.session.token = input.csrf ?? '';
Object.assign(B.globals, input.globals ?? {});

/** The server's HTML as the browser parses it. */
function parsed(html) {
    const template = document.createElement('template');
    template.innerHTML = html;
    return template.content.firstElementChild;
}

/** Without what differs by design (the runtime's attributes; children compared by id only), serialized as it is. */
function normalize(root, server) {
    if (!root) return '';
    for (const a of ['nitro:id', 'nitro:name', 'nitro:page']) root.removeAttribute?.(a);
    for (const el of [root, ...root.querySelectorAll('*')]) {
        if (!el.getAttributeNames) continue;
        for (const name of el.getAttributeNames()) if (name.startsWith('nitro:h-')) el.removeAttribute(name);
        /** Attributes in one order: theirs means nothing in HTML, and one a render adds goes last. */
        const attributes = el.getAttributeNames().sort().map((name) => [name, el.getAttribute(name)]);
        for (const [name] of attributes) el.removeAttribute(name);
        for (const [name, value] of attributes) el.setAttribute(name, value);
    }
    const children = server ? root.querySelectorAll('[nitro\:id]') : root.querySelectorAll('[nitro\:placeholder]');
    for (const child of children) {
        const marker = document.createElement('nitro-child');
        marker.setAttribute('id', child.getAttribute('nitro:id'));
        child.replaceWith(marker);
    }
    if (root === document.body) return root.innerHTML.trim();
    const holder = document.createElement('div');
    holder.appendChild(root);
    return holder.innerHTML.trim();
}

/** The component, then the page's portals (@teleport content goes there). */
const both = (component, server) => normalize(component, server) + '\n--- portals\n' + normalize(document.body, server);

const failures = [];

for (const c of input.cases) {
    const definition = definitions[c.name];
    if (!definition) {
        failures.push({ id: c.id, name: c.name, expected: 'a definition in the bundle', actual: 'none' });
        continue;
    }
    let data = P.decode(c.state);
    const ctx = Object.create(null);
    for (const name of Object.keys(definition.props)) {
        const prop = definition.props[name];
        Object.defineProperty(ctx, name, { get: () => (prop.enum ? enumCase(Nitro, prop, data.get(name)) : data.get(name) ?? null), set: () => { throw new Error('render wrote state'); } });
    }
    Object.assign(ctx, definition.methods);
    for (const [property, store] of Object.entries(c.stores ?? {})) {
        const storeCtx = storeContext(stores[store.name], store.state);
        Object.defineProperty(ctx, property, { get: () => storeCtx });
    }
    let errors = new ErrorBag(P.decode(c.errors));
    Object.defineProperty(ctx, '$errors', { get: () => errors });
    ctx.$computed = (name) => ctx[name]();

    /** The server's page: its portals with the content teleported to them. */
    document.body.innerHTML = c.page;
    const expected = both(parsed(c.ssr), true);
    const host = { on: () => {}, child: (name, key) => c.componentId + '.' + key };

    /** Built (the tree's parity), built with its bindings (anchors and all), and the server's HTML hydrated. */
    const ways = {
        built: () => {
            document.body.innerHTML = c.emptyPage;
            return both(new View(definition, ctx, host, document, false).create(), false);
        },
        tracked: () => {
            document.body.innerHTML = c.emptyPage;
            return both(new View(definition, ctx, host, document).create(), false);
        },
        hydrated: () => {
            document.body.innerHTML = c.page;
            const el = parsed(c.ssr);
            const server = [...el.querySelectorAll('*'), ...document.body.querySelectorAll('*')];
            new View(definition, ctx, host, document).hydrate(el);
            /** Hydration takes the server's elements over (in the portals too): none replaced by a new one, none created. */
            const now = [...el.querySelectorAll('*'), ...document.body.querySelectorAll('*')];
            const lost = server.filter((node) => !now.includes(node)).length;
            const made = now.filter((node) => !server.includes(node)).length;
            if (lost || made) return `hydration replaced elements: ${lost} of the server's gone, ${made} made`;
            return both(el, true);
        },
    };

    for (const [way, make] of Object.entries(ways)) {
        let actual;
        try {
            actual = make();
        } catch (error) {
            failures.push({ id: c.id, name: `${c.name} (${way})`, expected, actual: `JS error: ${error.stack}` });
            continue;
        }
        if (expected !== actual) failures.push({ id: c.id, name: `${c.name} (${way})`, expected, actual });
    }

    if (!c.next) continue;

    /** Rendered (built with its bindings, or hydrated), then the state changes: what reads what changed renders. */
    const first = { data, errors };
    const nextExpected = both(parsed(c.next.ssr), true);
    const change = (view, root) => {
        data = P.decode(c.next.state);
        errors = new ErrorBag(P.decode(c.next.errors));
        /** False: what changed is read around the root (an @if at the top), and the component makes its root again. */
        return view.update(new Set(c.next.changed)) ? root : view.create();
    };
    const updates = {
        'updated': () => {
            document.body.innerHTML = c.emptyPage;
            const view = new View(definition, ctx, host, document);
            const root = view.create();
            const holder = document.createElement('div');
            holder.appendChild(root);
            return both(change(view, root), false);
        },
        'hydrated, then updated': () => {
            document.body.innerHTML = c.page;
            const el = parsed(c.ssr);
            const holder = document.createElement('div');
            holder.appendChild(el);
            const view = new View(definition, ctx, host, document);
            view.hydrate(el);
            return both(change(view, el), true);
        },
    };

    for (const [way, make] of Object.entries(updates)) {
        ({ data, errors } = first);
        let actual;
        try {
            actual = make();
        } catch (error) {
            failures.push({ id: c.id, name: `${c.name} (${way})`, expected: nextExpected, actual: `JS error: ${error.stack}` });
            continue;
        }
        if (nextExpected !== actual) failures.push({ id: c.id, name: `${c.name} (${way})`, expected: nextExpected, actual });
    }
}

process.stdout.write(JSON.stringify(failures));
