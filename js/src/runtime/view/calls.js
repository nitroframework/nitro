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
import { CALLER, createScope, dispose, propagate, rootScope, run, track } from './scope.js';
import { codesIn } from './blocks.js';
import { render } from './render.js';
import { EMPTY_SLOT, SIGNALS } from '../protocol.js';

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
    /** __empty_name: whether that slot renders empty ($slot->isEmpty()), as the slot is now. */
    const slotOf = (input) => node.slots?.[input.slice(EMPTY.length)]?.h ?? [];
    const emptyInputs = [...names].filter((name) => name.startsWith(EMPTY));
    const empties = new Map(emptyInputs.map((name) => [name, rendersEmpty(instance, scope, slotOf(name))]));
    const owner = instance.$this;
    const $this = new Proxy(Object.create(null), {
        get: (_, key) => {
            if (empties.has(key)) return empties.get(key);
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
            if (key === 'slot') return (name) => (node.slots?.[name] ? { nodes: node.slots[name].h ?? [], instance, scope, trim: true } : undefined);
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
        /** Rendered aside, in a slot whose emptiness is being found (rendersEmpty()). */
        probe: instance.probe === true,
        /** The caller's nitro: attributes (name => its entries), rendered with it. */
        caller: { attributes: node.d ?? {}, instance, scope },
    };
    Object.defineProperty(call, 'live', { get: () => instance.live });
    /** The caller's $loop where the call is (l), or the one its caller has: the parent of the view's loops. */
    Object.defineProperty(call, 'callerLoop', { get: () => (node.l !== undefined ? scope.arguments[node.l] : instance.callerLoop) ?? null });

    const callScope = rootScope();
    scope.cleanups.push(() => dispose(call, callScope));

    /** The caller's $loop changed where the call is: the view's outermost loops have the new one as their parent (propagate()), and what reads them renders. */
    (scope.calls ??= []).push((positions) => {
        if (node.l !== undefined ? positions.includes(node.l) : positions.includes(CALLER)) {
            propagate(callScope, [CALLER]);
            run(call, new Set([SIGNALS.callerLoop]));
        }
    });

    /** The inputs follow the caller; the view's parts that read those that changed render. Its errors too, and its slots' emptiness. */
    const emptyCodes = emptyInputs.flatMap((name) => codesIn(slotOf(name)));
    track(instance, [node.i, SIGNALS.errors, ...emptyCodes], scope, () => {
        const next = evaluate(instance, node.i, scope);
        const changed = new Set([SIGNALS.errors]);
        for (const name of definition.inputs ?? []) {
            if (!empties.has(name) && !Object.is(P.get(inputs, name), P.get(next, name))) changed.add(name);
        }
        inputs = next;
        for (const name of emptyInputs) {
            const empty = rendersEmpty(instance, scope, slotOf(name));
            if (empty !== empties.get(name)) changed.add(name);
            empties.set(name, empty);
        }
        run(call, changed);
    });

    return [call, callScope, definition];
}

const EMPTY = EMPTY_SLOT;

/**
 * Whether a slot's content renders empty (ComponentSlot::isEmpty(): its output, trimmed, is ''):
 * rendered aside with the caller's instance, nothing of it live (a child component a stand-in, no
 * handlers, nothing tracked).
 */
function rendersEmpty(instance, scope, nodes) {
    if (nodes.length === 0) return true;
    /** The host's own for what it is asked here: a child is a stand-in; slots are the caller's; anything else does nothing. */
    const own = { child: () => 'probe', slot: (name) => instance.host.slot?.(name) };
    const host = new Proxy(instance.host, {
        get(target, key) {
            if (Object.hasOwn(own, key)) return own[key];
            return typeof target[key] === 'function' ? () => undefined : target[key];
        },
    });
    /** Its teleports and slot regions its own: nothing of it reaches the page (a @teleport renders nothing in place, as in Blade). */
    const probe = Object.create(instance, {
        host: { value: host },
        live: { value: false },
        leaks: { value: new WeakMap() },
        probe: { value: true },
        teleports: { value: new Set() },
        slotRegions: { value: new Set() },
    });
    const probeScope = createScope(scope, 'probe');
    probeScope.untracked = true;
    const holder = instance.document.createElement('div');
    try {
        render(holder, probe, nodes, probeScope, false, false);
    } finally {
        dispose(probe, probeScope);
    }
    /** Whitespace Blade's trim takes: not a character reference's (&#32;, trim.js trimLimits()). */
    return [...holder.childNodes].every((child) => child.nodeType === 3 && /^[ \t\n\r\0\x0B]*$/.test(child.nodeValue) && (child._limits?.start ?? Infinity) >= child.nodeValue.length);
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
