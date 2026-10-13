/**
 * Blade components, compiled once: each component's view (per the names its tags give) is a view
 * of its own, registered with Nitro.view(id, {template, expressions, subs, inputs}), and each use
 * is a call node, {t: "v"}:
 *
 *   v: the view's id
 *   i: the inputs, an expression of the caller's (a map: input => value)
 *   d: the caller's nitro: attributes, compiled in the caller, by name as written: rendered on the
 *      element the component's $attributes put them on ({d: name}), with the caller's instance
 *      (its handlers, its models)
 *   slots: by name, the caller's nodes, rendered with the caller's instance and scope (as a
 *      child component's slots)
 *
 * The view renders with an instance of its own: its expressions, what they read (its inputs, by
 * name), its registry, and its inputs as $this (anything else on it, such as $errors and its own
 * nitro: attributes' handlers, is the caller's: the Nitro component's). It shares the caller's
 * host, document, owner, anchors (hydration) and teleports. The caller tracks the
 * inputs' expression: when they change, the parts of the view that read the inputs that changed
 * render, as a component's parts render when its properties change.
 */
import * as P from '../../php.js';
import { evaluate } from './call.js';
import { createScope, dispose, rootScope, run, track } from './scope.js';
import { SIGNALS } from '../protocol.js';

const views = new Map();

/** Nitro.view(id, definition): a Blade component's view, compiled once. */
export function defineView(id, definition) {
    views.set(id, definition);
}

/**
 * The instance a call renders its view with, and the scope it renders in: made, and kept in step
 * with the caller's inputs, slots and errors.
 */
export function callInstance(instance, node, scope) {
    const definition = views.get(node.v);
    if (!definition) throw new Error(`Nitro: no compiled view ${node.v} (the bundle is older than the page?)`);

    let inputs = evaluate(instance, node.i, scope);
    /** Its inputs; anything else (a handler's methods and models, $errors) is the caller's: the Nitro component's. */
    const names = new Set(definition.inputs ?? []);
    const owner = instance.$this;
    const $this = new Proxy(Object.create(null), {
        get: (_, key) => {
            if (names.has(key)) return P.get(inputs, key);
            const value = Reflect.get(owner, key);
            return typeof value === 'function' ? value.bind(owner) : value;
        },
        set: (_, key, value) => Reflect.set(owner, key, value),
        has: (_, key) => names.has(key) || key in owner,
    });

    const host = new Proxy(instance.host, {
        get(target, key) {
            /** A slot of the call: the caller's nodes, with the caller's instance and scope. */
            if (key === 'slot') return (name) => (node.slots?.[name] ? { nodes: node.slots[name].h ?? [], instance, scope } : undefined);
            const value = target[key];
            return typeof value === 'function' ? value.bind(target) : value;
        },
    });

    const call = {
        $this,
        $$t: definition.expressions,
        subs: definition.subs ?? [],
        $$r: new Map(),
        host,
        document: instance.document,
        ownerId: instance.ownerId,
        anchors: instance.anchors,
        leaks: new WeakMap(),
        rootSubs: new Set(),
        slotRegions: new Set(),
        teleports: instance.teleports,
        /** The caller's nitro: attributes (name => its entries), rendered with it. */
        caller: { attributes: node.d ?? {}, instance, scope },
    };
    Object.defineProperty(call, 'live', { get: () => instance.live });

    const callScope = rootScope();
    scope.cleanups.push(() => dispose(call, callScope));

    /** The inputs follow the caller; the view's parts that read those that changed render. Its errors too. */
    track(instance, [node.i, SIGNALS.errors], scope, () => {
        const next = evaluate(instance, node.i, scope);
        const changed = new Set([SIGNALS.errors]);
        for (const name of definition.inputs ?? []) {
            if (!Object.is(P.get(inputs, name), P.get(next, name))) changed.add(name);
        }
        inputs = next;
        run(call, changed);
    });

    return [call, callScope, definition];
}

/** A call node in a view: its view, rendered in place. */
export function renderCall(target, instance, node, scope, hydrate, insert, render) {
    const [call, callScope, definition] = callInstance(instance, node, scope);
    return render(target, call, definition.template.h ?? [], createScope(callScope, 'call'), hydrate, insert);
}

/**
 * A view's root that is a call: the call's view's root element, and the instance and scope it
 * renders with (a call in it, in turn: its own).
 */
export function callRoot(instance, node, scope) {
    const [call, callScope, definition] = callInstance(instance, node, scope);
    return { instance: call, scope: createScope(callScope, 'call'), nodes: definition.template.h ?? [] };
}

/**
 * An element's attributes as they render: its own (scope null: the element's), and in a called
 * view the caller's nitro: attributes it is given (d: the attribute's name as written), each with
 * the instance and scope it renders with.
 *
 * @returns {Array<{attribute: object, instance: object, scope: object|null}>}
 */
export function attributesOf(instance, node) {
    const out = [];
    for (const attribute of node.a ?? []) {
        if (attribute.d === undefined) {
            out.push({ attribute, instance, scope: null });
            continue;
        }
        const caller = instance.caller;
        for (const given of caller?.attributes?.[attribute.d] ?? []) out.push({ attribute: given, instance: caller.instance, scope: caller.scope });
    }
    return out;
}
