/**
 * Viewi's renderForeach (core/render/renderForeach): an iteration whose value (and key) is still
 * there keeps its nodes, moved to its new place; new values get new iterations; those gone are
 * removed.
 *
 * Nitro: an array at another key (a list reindexed) is still the same item, and moves. A write
 * replaces a value where Viewi changes it in place, so an iteration whose value was replaced
 * keeps its nodes too, by its key, and takes the new value: what reads its variables renders
 * after this (it reads what the loop reads), so a row being typed into keeps its field. An
 * iteration bound to its position (a child keyed by the loop's index) renders again when it moves.
 */
import * as B from '../../laravel/blade.js';
import * as P from '../../php.js';
import { createAnchorNode, inPlace, nextAnchorNodeId, removeBefore } from './anchor.js';
import { evaluate } from './call.js';
import { render } from './render.js';
import { createScope, dispose, propagate } from './scope.js';
import { motionless, move, positions, transitionOf } from './transition.js';

/**
 * A @foreach's $loop->parent: the $loop where the loop opens (parentLoop: its position); for a
 * Blade component's view's outermost loops, the caller's $loop (calls.js); else null.
 */
export function parentLoop(instance, directive, scope) {
    return (directive.parentLoop === undefined ? instance.callerLoop : scope.arguments[directive.parentLoop]) ?? null;
}

/**
 * An iteration's scope: its variables (its key, its value, $loop) its own, and where its $loop
 * and its parent's are, for propagate() to keep $loop->parent the loop around it as that changes.
 */
export function iterationScope(instance, scope, why, directive, key, value, loop) {
    const own = [directive.item, directive.loop, ...(directive.key !== undefined ? [directive.key] : [])];
    const iteration = createScope(scope, why, setLoopArguments(scope.arguments.slice(), directive, key, value, loop), own);
    iteration.loops = [...scope.loops, loop.index];
    iteration.loopAt = directive.loop;
    iteration.parentAt = directive.parentLoop;
    if (directive.parentLoop === undefined) iteration.callerLoop = () => instance.callerLoop;
    return iteration;
}

/**
 * The variables a loop leaves set as PHP's does, read after it (the compiler's left: [its position
 * in the loop, where it is read, and for a loop that ran no iteration: absent, it keeps its value;
 * null, null; else the expression giving what it was before]). last: the arguments the loop's
 * variables last had (its last iteration's, the one a @break left; @for's after it), null when
 * none ran. Set in the scope the loop renders in (in a block rendered again as a whole, the one
 * around that: around); returns that scope and the positions that changed, for propagate().
 */
export function leaveLoop(instance, directive, scope, last) {
    let into = scope;
    while (into.around) into = into.around;
    const changed = [];
    for (const [from, to, before] of directive.left ?? []) {
        if (!last && before === undefined) continue;
        const value = last ? last[from] : before === null ? null : evaluate(instance, before, into);
        if (!Object.is(into.arguments[to], value)) changed.push(to);
        into.arguments[to] = value;
    }
    return [into, changed];
}

/** An iteration's variables, at their positions: its key, its value, $loop. */
export function setLoopArguments(args, directive, key, value, loop) {
    if (directive.key !== undefined) args[directive.key] = key;
    args[directive.item] = value;
    args[directive.loop] = loop;
    return args;
}

export function renderForeach(instance, node, anchors, currentArrayScope, scope, ifConditions) {
    if (scope.disposed || !inPlace(anchors.anchorNode)) {
        return;
    }
    const directive = node.i[0];
    const noKey = directive.key === undefined;
    const data = evaluate(instance, directive.forData, scope);
    const state = B.loop(data, parentLoop(instance, directive, scope));

    const current = [...currentArrayScope.data.values()];
    const usedMap = new Set();

    /** nitro:transition.move: where its rows are before anything changes. */
    const movers = moversIn(current);
    const before = movers.length ? positions(movers.map(([el]) => el)) : null;

    /** Which iterations stay, and where: worked out before anything moves. */
    const matches = [];
    let positionIndex = -1;
    for (const [dataKey, dataItem] of P.iter(data)) {
        positionIndex++;
        let foundIndex = current.findIndex((item) => !usedMap.has(item) && item.value === dataItem && (noKey || item.key === dataKey));
        /** Nitro: an array at another key (a list reindexed) is the same item: it moves. */
        if (foundIndex === -1 && typeof dataItem === 'object' && dataItem !== null) {
            foundIndex = current.findIndex((item) => !usedMap.has(item) && item.value === dataItem);
        }
        /** Nitro: a value replaced by a write keeps the iteration of its key. */
        if (foundIndex === -1) {
            foundIndex = current.findIndex((item) => !usedMap.has(item) && item.key === dataKey);
        }
        if (foundIndex !== -1 && directive.positional && current[foundIndex].index !== positionIndex) {
            foundIndex = -1;
        }
        if (foundIndex !== -1) usedMap.add(current[foundIndex]);
        matches.push({ dataKey, dataItem, positionIndex, foundIndex });
    }

    /**
     * Those gone are removed first: a row that leaves animated (nitro:transition) stays where it
     * was meanwhile, and the rows are placed around it.
     */
    for (const item of current) {
        if (!usedMap.has(item)) {
            removeBefore(instance, item.end);
            item.begin.remove();
            item.end.remove();
            dispose(instance, item.scope);
        }
    }

    let moveBefore = pastLeaving(anchors.anchorBegin.nextSibling);
    const nextArrayScope = new Map();
    let last = null;
    for (const { dataKey, dataItem, positionIndex, foundIndex } of matches) {
        const loop = B.next(state);
        if (foundIndex !== -1) {
            const currentScopeItem = current[foundIndex];
            last = currentScopeItem.scope.arguments;
            /** Its variables as they are now; what changed goes to the blocks in it that copied them (propagate()). */
            const args = currentScopeItem.scope.arguments;
            const next = setLoopArguments(scope.arguments.slice(), directive, dataKey, dataItem, loop);
            const changed = next.flatMap((value, position) => (Object.is(args[position], value) ? [] : [position]));
            Object.assign(args, next);
            if (changed.length) propagate(currentScopeItem.scope, changed);
            currentScopeItem.key = dataKey;
            currentScopeItem.value = dataItem;
            currentScopeItem.index = positionIndex;
            nextArrayScope.set(dataKey, currentScopeItem);
            if (foundIndex !== positionIndex && moveBefore !== currentScopeItem.begin) {
                // move html
                const beginAnchor = currentScopeItem.begin;
                let nextToMove = beginAnchor.nextSibling;
                moveBefore.before(beginAnchor);
                while (nextToMove._anchor !== beginAnchor._anchor) {
                    nextToMove = nextToMove.nextSibling;
                    moveBefore.before(nextToMove.previousSibling);
                }
                moveBefore.before(nextToMove);
            }
            moveBefore = pastLeaving(currentScopeItem.end.nextSibling);
        } else {
            const nextScope = iterationScope(instance, scope, 'forItem', directive, dataKey, dataItem, loop);
            last = nextScope.arguments;
            const itemBeginAnchor = createAnchorNode(instance, moveBefore, true, undefined, 'B' + nextAnchorNodeId()); // begin foreach item
            render(moveBefore, instance, node.h ?? [], nextScope, false, true);
            const itemEndAnchor = createAnchorNode(instance, moveBefore, true, undefined, itemBeginAnchor._anchor); // end foreach item
            moveBefore = pastLeaving(itemEndAnchor.nextSibling);
            nextArrayScope.set(dataKey, { key: dataKey, value: dataItem, index: positionIndex, begin: itemBeginAnchor, end: itemEndAnchor, scope: nextScope });
        }
    }
    currentArrayScope.data = nextArrayScope;
    ifConditions.values[0] = positionIndex > -1;

    /** What it leaves set, read after it: what reads it renders after this (it reads what the loop reads). */
    const [into, changed] = leaveLoop(instance, directive, scope, last);
    if (changed.length) propagate(into, changed);

    /** Each row that stays slides from where it was to where it is now. */
    if (before) {
        for (const [el, spec] of movers) {
            if (el.isConnected && !el._leaving) move(el, spec, before.get(el));
        }
    }
}

/** The rows' elements with nitro:transition.move (not those leaving, nor in a tab that is hidden). */
function moversIn(items) {
    const movers = [];
    for (const item of items) {
        for (let node = item.begin.nextSibling; node && node !== item.end; node = node.nextSibling) {
            const spec = transitionOf(node);
            if (spec?.move && !node._leaving && !motionless(node)) movers.push([node, spec]);
        }
    }
    return movers;
}

/** Past the nodes still leaving (nitro:transition): they keep their place until they go. */
function pastLeaving(node) {
    while (node?._leaving) node = node.nextSibling;
    return node;
}
