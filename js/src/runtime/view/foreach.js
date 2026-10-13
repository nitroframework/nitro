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
import { createScope, dispose } from './scope.js';
import { motionless, move, positions, transitionOf } from './transition.js';

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
    const state = B.loop(data, directive.parentLoop ? scope.arguments[directive.loop] : null);

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
    for (const { dataKey, dataItem, positionIndex, foundIndex } of matches) {
        const loop = B.next(state);
        if (foundIndex !== -1) {
            const currentScopeItem = current[foundIndex];
            Object.assign(currentScopeItem.scope.arguments, setLoopArguments(scope.arguments.slice(), directive, dataKey, dataItem, loop));
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
            const nextScope = createScope(scope, 'forItem', setLoopArguments(scope.arguments.slice(), directive, dataKey, dataItem, loop));
            nextScope.loops = [...scope.loops, loop.index];
            const itemBeginAnchor = createAnchorNode(instance, moveBefore, true, undefined, 'B' + nextAnchorNodeId()); // begin foreach item
            render(moveBefore, instance, node.h ?? [], nextScope, false, true);
            const itemEndAnchor = createAnchorNode(instance, moveBefore, true, undefined, itemBeginAnchor._anchor); // end foreach item
            moveBefore = pastLeaving(itemEndAnchor.nextSibling);
            nextArrayScope.set(dataKey, { key: dataKey, value: dataItem, index: positionIndex, begin: itemBeginAnchor, end: itemEndAnchor, scope: nextScope });
        }
    }
    currentArrayScope.data = nextArrayScope;
    ifConditions.values[0] = positionIndex > -1;

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
