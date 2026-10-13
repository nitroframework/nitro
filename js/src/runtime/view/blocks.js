/** Blade blocks (@if chains, @foreach, @for, @while, @switch), and what they read. Part of render.js. */
import * as B from '../../laravel/blade.js';
import * as P from '../../php.js';
import { createAnchorNode, getAnchor, nextAnchorNodeId } from './anchor.js';
import { evaluate } from './call.js';
import { renderDynamic } from './dynamic.js';
import { renderForeach, setLoopArguments } from './foreach.js';
import { conditionOf, renderIf } from './if.js';
import { createScope, track } from './scope.js';
import { LOOPS, render } from './render.js';
import { SIGNALS } from '../protocol.js';

// ------------------------------------------------------------ blocks

/**
 * A block and those continuing it (@elseif, @else, @empty). Returns a @break/@continue leaving
 * it, or the conditions a following @else continues from.
 */
export function renderChain(target, instance, chain, scope, hydrate, insert, ifConditions) {
    const first = chain[0].i[0].c;

    /** Nitro: untracked (nitro:ignore), or a block a @break leaves: part of what is around it. */
    if (scope.untracked || chain.some((node) => leak(instance, node) > 0)) {
        return renderBranches(target, instance, chain, scope, hydrate, insert);
    }

    /** Nitro: a loop a @break ends, @for, @while and @switch render again as a whole. */
    if (first === 'for' || first === 'while' || first === 'switch' || (first === 'foreach' && innerLeak(instance, chain[0]) > 0)) {
        const inlined = chain.flatMap((node) => inlinedCodes(instance, node.i[0].c === 'switch' ? (node.h ?? []).flatMap((c) => c.h ?? []) : node.h ?? []));
        const codes = [...chain.flatMap((node) => directiveCodes(node)), ...inlined];
        const choose = first === 'switch' && inlined.length === 0 ? (s) => switchCase(instance, chain[0], s) : null;
        renderDynamic(target, instance, scope, hydrate, insert, codes, (into, regionScope, h, ins) => renderBranches(into, instance, chain, regionScope, h, ins), choose);
        return null;
    }

    for (const node of chain) {
        const directive = node.i[0];
        switch (directive.c) {
            case 'if':
            case 'error':
                ifConditions = { values: [], index: 0, subs: [] };
                renderCondition(target, instance, node, scope, hydrate, insert, ifConditions);
                break;
            case 'else-if':
            case 'else':
            case 'empty':
                if (!ifConditions) {
                    console.warn(`Nitro: @${directive.c} has no block before it`, directive);
                    break;
                }
                renderCondition(target, instance, node, scope, hydrate, insert, ifConditions);
                break;
            case 'foreach':
                ifConditions = renderForeachBlock(target, instance, node, scope, hydrate, insert);
                break;
            default:
                throw new Error(`Nitro: unknown directive ${directive.c}`);
        }
    }
    return ifConditions;
}

/** Viewi's if / else-if / else (and Nitro's @error, @empty): a branch between anchors. */
function renderCondition(target, instance, node, scope, hydrate, insert, ifConditions) {
    const directive = node.i[0];
    let nextValue = true;
    for (let ifv = 0; ifv < ifConditions.index; ifv++) {
        nextValue = nextValue && !ifConditions.values[ifv];
    }
    let args = scope.arguments;
    if (nextValue) {
        const condition = conditionOf(instance, directive, scope);
        nextValue = condition.value;
        args = condition.args;
    }
    ifConditions.values.push(nextValue);
    const anchor = hydrate ? getAnchor(instance, target) : undefined;
    const anchorBegin = createAnchorNode(instance, target, insert, anchor); // begin if
    const nextScope = createScope(scope, directive.c, args);
    if (nextValue) {
        render(target, instance, node.h ?? [], nextScope, hydrate, insert);
    }
    const anchorNode = createAnchorNode(instance, target, insert, anchor, anchorBegin._anchor); // end if
    ifConditions.subs = ifConditions.subs.concat(directiveCodes(node));
    const container = { scope: nextScope, anchorNode };
    const index = ifConditions.index;
    track(instance, ifConditions.subs, scope, () => renderIf(instance, node, container, ifConditions, index));
    ifConditions.index++;
}

/** Viewi's foreach: each iteration between anchors of its own, in a scope with its variables. */
function renderForeachBlock(target, instance, node, scope, hydrate, insert) {
    const directive = node.i[0];
    const data = evaluate(instance, directive.forData, scope);
    const anchor = hydrate ? getAnchor(instance, target) : undefined;
    const anchorBegin = createAnchorNode(instance, target, insert, anchor); // begin foreach
    /** Nitro: a Map keeps PHP's order and its keys' types (Viewi keeps an object). */
    const dataArrayScope = { data: new Map() };
    const state = B.loop(data, directive.parentLoop ? scope.arguments[directive.loop] : null);
    for (const [dataKey, dataItem] of P.iter(data)) {
        const loop = B.next(state);
        const nextScope = createScope(scope, 'foreach', setLoopArguments(scope.arguments.slice(), directive, dataKey, dataItem, loop));
        nextScope.loops = [...scope.loops, loop.index];
        const itemBeginAnchor = createAnchorNode(instance, target, insert, anchor, 'B' + nextAnchorNodeId()); // begin foreach item
        render(target, instance, node.h ?? [], nextScope, hydrate, insert);
        const itemEndAnchor = createAnchorNode(instance, target, insert, anchor, itemBeginAnchor._anchor); // end foreach item
        dataArrayScope.data.set(dataKey, { key: dataKey, value: dataItem, index: loop.index, begin: itemBeginAnchor, end: itemEndAnchor, scope: nextScope });
    }
    const anchorNode = createAnchorNode(instance, target, insert, anchor, anchorBegin._anchor); // end foreach

    /** Nitro: @forelse's @empty continues from whether there were items. */
    const ifConditions = { values: [dataArrayScope.data.size > 0], index: 1, subs: [directive.forData] };
    track(instance, [directive.forData], scope, () => renderForeach(instance, node, { anchorBegin, anchorNode }, dataArrayScope, scope, ifConditions));
    return ifConditions;
}

// ------------------------------------------------------------ blocks as part of what is around them

/** The chain rendered in place (no anchors): its branch, its loop's iterations. */
export function renderBranches(target, instance, chain, scope, hydrate, insert) {
    for (const node of chain) {
        const directive = node.i[0];
        const children = node.h ?? [];

        switch (directive.c) {
            case 'if':
            case 'else-if':
            case 'else':
            case 'empty':
            case 'error': {
                const condition = conditionOf(instance, directive, scope);
                if (!condition.value) break;
                const branchScope = condition.args === scope.arguments ? scope : createScope(scope, directive.c, condition.args);
                return render(target, instance, children, branchScope, hydrate, insert);
            }
            case 'foreach': {
                const [signal, produced] = foreachInPlace(target, instance, directive, children, scope, hydrate, insert);
                if (signal || produced) return signal;
                break;
            }
            case 'for':
            case 'while':
                return iterations(target, instance, directive, children, scope, hydrate, insert);
            case 'switch': {
                const start = switchCase(instance, node, scope);
                for (let i = start; i !== -1 && i < children.length; i++) {
                    const signal = render(target, instance, children[i].h ?? [], scope, hydrate, insert);
                    if (signal) return signal.levels > 1 ? { kind: signal.kind, levels: signal.levels - 1 } : undefined;
                }
                return undefined;
            }
            default:
                throw new Error(`Nitro: unknown directive ${directive.c}`);
        }
    }
    return undefined;
}

/** @foreach in place: returns [signal, whether it had items]. */
function foreachInPlace(target, instance, directive, children, scope, hydrate, insert) {
    const data = evaluate(instance, directive.forData, scope);
    const state = B.loop(data, directive.parentLoop ? scope.arguments[directive.loop] : null);
    let produced = false;

    for (const [key, value] of P.iter(data)) {
        produced = true;
        const loop = B.next(state);
        const iterationScope = createScope(scope, 'foreach', setLoopArguments(scope.arguments.slice(), directive, key, value, loop));
        iterationScope.loops = [...scope.loops, loop.index];

        const signal = render(target, instance, children, iterationScope, hydrate, insert);
        if (signal) {
            if (signal.levels > 1) return [{ kind: signal.kind, levels: signal.levels - 1 }, produced];
            if (signal.kind === 'break') break;
        }
    }
    return [undefined, produced];
}

/** @for / @while: the expression works out each iteration's variables. */
function iterations(target, instance, directive, children, scope, hydrate, insert) {
    for (const values of evaluate(instance, directive.code, scope)) {
        const args = scope.arguments.slice();
        (directive.vars ?? []).forEach((position, i) => (args[position] = values[i]));

        const signal = render(target, instance, children, createScope(scope, directive.c, args), hydrate, insert);
        if (signal) {
            if (signal.levels > 1) return { kind: signal.kind, levels: signal.levels - 1 };
            if (signal.kind === 'break') break;
        }
    }
    return undefined;
}

/** @switch, as PHP's: the matching @case (else @default), -1 for none. */
function switchCase(instance, node, scope) {
    const cases = node.h ?? [];
    const subject = evaluate(instance, node.i[0].code, scope);
    const start = cases.findIndex((c) => c.i[0].c === 'case' && P.eq(subject, evaluate(instance, c.i[0].code, scope)));
    return start !== -1 ? start : cases.findIndex((c) => c.i[0].c === 'default');
}

// ------------------------------------------------------------ what blocks read

/** How many loops beyond it a @break/@continue in the node can end. */
function leak(instance, node) {
    if (node.t === 'b') return node.levels ?? 1;
    if (node.t !== 'template') return 0;
    if (!instance.leaks.has(node)) {
        const inner = innerLeak(instance, node);
        instance.leaks.set(node, LOOPS.has(node.i[0].c) ? Math.max(0, inner - 1) : inner);
    }
    return instance.leaks.get(node);
}

function innerLeak(instance, node) {
    return Math.max(0, ...(node.h ?? []).map((n) => leak(instance, n)));
}

/** The expressions a block's directive (and a @switch's cases) evaluate. */
export function directiveCodes(node) {
    const directive = node.i[0];
    const codes = [directive.code, directive.forData].filter((code) => code !== undefined);
    if (directive.c === 'error') codes.push(SIGNALS.errors);
    if (directive.c === 'switch') {
        for (const c of node.h ?? []) if (c.i[0].code !== undefined) codes.push(c.i[0].code);
    }
    return codes;
}

/** The expressions of an element's attribute values (handlers are bound on their own; a caller's attribute, d, is the caller's to track). */
export function attributeCodes(node) {
    const codes = [];
    for (const attribute of node.a ?? []) {
        if (attribute.d !== undefined) continue;
        if (attribute.when !== undefined) codes.push(attribute.when);
        for (const part of attribute.h ?? []) if (part.e) codes.push(part.code);
    }
    return codes;
}

/** Every expression in the nodes. */
export function codesIn(nodes) {
    const codes = [];
    for (const node of nodes) {
        if (node.t === 'x' && node.e) codes.push(node.code);
        if (node.t === 't') codes.push(...attributeCodes(node));
        if (node.t === 'c') codes.push(node.props, ...(node.key !== undefined ? [node.key] : []), ...(node.is !== undefined ? [node.is] : []));
        /** A call: its inputs, the attributes and slots it is given (the caller's); its view tracks its own. */
        if (node.t === 'v') {
            codes.push(node.i, ...attributeCodes({ a: Object.values(node.d ?? {}).flat() }));
            for (const slot of Object.values(node.slots ?? {})) codes.push(...codesIn(slot.h ?? []));
        }
        if (node.t === 'b' && node.code !== undefined) codes.push(node.code);
        if (node.t === 'template') codes.push(...directiveCodes(node));
        codes.push(...codesIn(node.h ?? []));
    }
    return codes;
}

/** What the blocks rendered in place read (those a @break leaves, the @break conditions). */
function inlinedCodes(instance, nodes) {
    const codes = [];
    for (const node of nodes) {
        if (node.t === 'b' && node.code !== undefined) codes.push(node.code);
        if (node.t === 't') codes.push(...inlinedCodes(instance, node.h ?? []));
        if (node.t === 'template' && leak(instance, node) > 0) {
            codes.push(...directiveCodes(node), ...inlinedCodes(instance, node.i[0].c === 'switch' ? (node.h ?? []).flatMap((c) => c.h ?? []) : node.h ?? []));
        }
    }
    return codes;
}
