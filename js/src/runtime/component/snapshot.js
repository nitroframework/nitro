/**
 * A component's state as the server signed it: read from its envelope (the fields js/protocol.json
 * lists under snapshot), and taken in after a #[Server] call or a reload, keeping what changed in
 * the browser meanwhile.
 */
import * as P from '../../php.js';
import { merge as mergeValues } from './merge.js';
import { ErrorBag } from './errors.js';
import { SIGNALS } from '../protocol.js';

/** The state inside a signed envelope: {name, id, data: Map, errors}. */
export function readState(envelope) {
    const state = P.decode(JSON.parse(envelope.state));
    const get = (key) => (state instanceof Map ? state.get(key) : undefined);
    const data = get('data');
    const memo = get('memo');
    return {
        name: get('name'),
        id: get('id'),
        data: data instanceof Map ? data : new Map(),
        errors: new ErrorBag(memo instanceof Map ? memo.get('errors') ?? null : null),
        /** A page's properties loaded after it is shown ($this->defer()). */
        deferred: memo instanceof Map ? P.entries(memo.get('deferred') ?? []).map(([, name]) => name) : [],
        /** A partial reload's merge props: property => its rule (Merge). */
        merge: memo instanceof Map && memo.get('merge') instanceof Map ? memo.get('merge') : new Map(),
        /** A page's scroll props: property => where its paginator is ($this->scroll()). */
        scroll: memo instanceof Map && memo.get('scroll') instanceof Map ? memo.get('scroll') : new Map(),
        /** A component placed in a Blade view: its slots' HTML (no parent renders them in the browser). */
        slots: memo instanceof Map && memo.get('slots') instanceof Map ? memo.get('slots') : null,
    };
}

/** A merge rule as it came (a Map): {mode, path, match}. */
function readRule(rule) {
    return { mode: rule.get('mode'), path: rule.get('path') ?? null, match: rule.get('match') ?? [] };
}

/** Two PHP values are the same (as encoded: types count). */
export function sameValue(a, b) {
    return a === b || JSON.stringify(P.encode(a ?? null)) === JSON.stringify(P.encode(b ?? null));
}

/**
 * The state after a #[Server] call; values changed here while it ran stay changed. What the
 * server changed renders again (a value equal to the one here stays the one here).
 */
/** The state a definition starts from: its properties' defaults (those that have one). */
export function defaultsOf(definition) {
    const data = new Map();
    for (const [name, prop] of Object.entries(definition.props)) {
        if ('default' in prop) data.set(name, P.decode(prop.default));
    }
    return data;
}

/** A component's or a store's values changed here since its last signed state: sent with the next call (never a locked one). */
export function updatesOf(holder) {
    const updates = {};
    for (const [name, value] of holder.data) {
        if (holder.definition.props[name]?.locked) continue;
        if (holder.serverData.get(name) !== value) updates[name] = P.encode(value);
    }
    return updates;
}

export function snapshotInto(component, envelope, sent) {
    const { data, errors, scroll } = readState(envelope);
    const fresh = new Map();
    component.setScroll(scroll, true);

    for (const [name, value] of data) {
        const current = component.data.get(name);
        if (component.data.has(name) && sent.get(name) !== current) {
            fresh.set(name, current);
        } else if (sameValue(current, value)) {
            fresh.set(name, current);
        } else {
            fresh.set(name, value);
            component.dirty.add(name);
        }
    }

    if (JSON.stringify([...errors.messages]) !== JSON.stringify([...component.errors.messages])) component.dirty.add(SIGNALS.errors);

    component.snapshot = envelope;
    component.serverData = new Map(data);
    component.data = fresh;
    component.errors = errors;
    component.memo.clear();
    const modelable = component.definition.modelable;
    const modelChanged = modelable && component.dirty.has(modelable);
    component.update();
    /** The server changed what nitro:model binds: the parent follows. */
    if (modelChanged) component.modelChanged(modelable);
    /** Its changes are the server's now, though nothing may have rendered again. */
    component.runtime.unsavedChanged(component);
}

/**
 * The state after a reload ($reload, deferred properties). Of some properties: their signed
 * values (partial) are taken, unless changed here while it ran, and the envelope is kept for
 * the next #[Server] call, which lays it over the snapshot. Of all of them: the new snapshot
 * is the state, again keeping what changed here meanwhile.
 */
export function reloadInto(component, envelope, only, sent, partial = null) {
    if (only.length > 0 && partial) {
        const { data, merge: rules, scroll } = readState(partial);
        component.setScroll(scroll, false);
        for (const [name, value] of data) {
            if (!only.includes(name)) continue;
            const current = component.data.get(name);
            const changedHere = component.data.has(name) && sent.get(name) !== current;
            /** A merge prop: merged into the value here, as the server merges it into its own (Merge::apply()). */
            const rule = rules.get(name);
            const into = (base) => (rule ? mergeValues(base ?? null, value, readRule(rule)) : value);
            component.serverData.set(name, into(component.serverData.get(name)));
            const next = into(current);
            if (!changedHere && !sameValue(current, next)) {
                component.data.set(name, next);
                component.dirty.add(name);
            }
        }
        component.partials.push(partial);
    } else {
        const { data, scroll } = readState(envelope);
        component.setScroll(scroll, true);
        const fresh = new Map(component.data);
        for (const [name, value] of data) {
            const current = component.data.get(name);
            const changedHere = component.data.has(name) && sent.get(name) !== current;
            if (!changedHere && !sameValue(current, value)) {
                fresh.set(name, value);
                component.dirty.add(name);
            }
        }
        component.snapshot = envelope;
        component.serverData = new Map(data);
        component.partials = [];
        component.data = fresh;
    }

    component.memo.clear();
    const modelable = component.definition.modelable;
    const modelChanged = modelable && component.dirty.has(modelable);
    component.update();
    if (modelChanged) component.modelChanged(modelable);
    component.runtime.unsavedChanged(component);
}
