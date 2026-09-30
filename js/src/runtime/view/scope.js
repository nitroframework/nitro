/**
 * Viewi's context scopes and reactivity (core/lifecycle, core/reactivity/track): every block
 * rendered has a scope, a child of the one it renders in, holding the template's variables
 * (arguments, by position) and what it tracks. Disposing a scope forgets all it tracked, and its
 * children's.
 *
 * instance.$$r is Viewi's registry: property => (id => binding). A binding is tracked once for
 * all the properties it reads (Viewi tracks it once per property), so a change to several of
 * them runs it once.
 */
let trackingId = 0;

/** A scope inside another (Viewi's nextScope literals). */
export function createScope(parent, why, args = parent.arguments) {
    const scope = {
        id: ++parent.counter,
        why,
        arguments: args,
        /** Fusion: indexes of the @foreach loops around it (a child's key has them) */
        loops: parent.loops,
        depth: parent.depth + 1,
        track: [],
        parent,
        children: {},
        counter: 0,
        untracked: parent.untracked,
        /** Fusion: what else goes when it does (a slot's content, which its parent's view tracks). */
        cleanups: [],
    };
    parent.children[scope.id] = scope;
    return scope;
}

export function rootScope() {
    return { id: 0, why: 'root', arguments: [], loops: [], depth: 0, track: [], parent: null, children: {}, counter: 0, untracked: false, cleanups: [] };
}

/** What the expressions read (Fusion: the compiler's subs, by expression); a string is a key itself. */
export function keysOf(instance, codes) {
    const keys = new Set();
    for (const code of codes) {
        if (code === undefined) continue;
        if (typeof code === 'string') keys.add(code);
        else for (const key of instance.subs[code] ?? ['*']) keys.add(key);
    }
    return keys;
}

/** Viewi's track: run the action when a property the expressions read changes. */
export function track(instance, codes, scope, action) {
    if (scope.untracked) return;
    const keys = keysOf(instance, codes);
    if (keys.size === 0) return;
    const id = ++trackingId;
    const binding = { id, scope, action, keys };
    for (const key of keys) {
        if (!instance.$$r.has(key)) instance.$$r.set(key, new Map());
        instance.$$r.get(key).set(id, binding);
    }
    scope.track.push(binding);
}

/** Viewi's dispose: forget what the scope and the scopes in it track. */
export function dispose(instance, scope) {
    for (const cleanup of scope.cleanups.splice(0)) cleanup();
    for (const binding of scope.track) {
        for (const key of binding.keys) instance.$$r.get(key)?.delete(binding.id);
    }
    scope.track = [];
    for (const id in scope.children) dispose(instance, scope.children[id]);
    scope.children = {};
    if (scope.parent) {
        delete scope.parent.children[scope.id];
    }
    scope.disposed = true;
}

/**
 * Viewi's schedule and executeQueue, for the properties that changed ("*": any). Viewi runs the
 * queue in a timeout, in the order it was tracked; Fusion runs it when the component updates (a
 * microtask), outer scopes first: a @foreach gives its iterations their new values before what is
 * in them reads them, and a block rendered again leaves nothing of its old bindings to run.
 */
export function run(instance, names) {
    const queue = new Map();
    const all = names.has('*');
    for (const [key, bindings] of instance.$$r) {
        if (!all && key !== '*' && !names.has(key)) continue;
        for (const [id, binding] of bindings) queue.set(id, binding);
    }
    const ordered = [...queue.values()].sort((a, b) => a.scope.depth - b.scope.depth || a.id - b.id);
    for (const binding of ordered) {
        if (!binding.scope.disposed) binding.action();
    }
}
