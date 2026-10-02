/**
 * Viewi's render (core/render/render.ts), for Fusion's templates: render nodes into a target
 * element, hydrating the server's HTML (hydrate) or making new nodes, at the target's end or
 * before it (insert: the target is an anchor), and track what each part reads.
 *
 *   node.t: "t" tag, "x" text, "m" comment, "c" child component, "b" @break/@continue,
 *           "template" a Blade block, its directive in i (Viewi puts directives on the tag;
 *           Blade's wrap any nodes, as Viewi's <template if="...">)
 *
 * Blocks, as Viewi's: an @if chain renders a branch between anchors, again only when which
 * branch it is changes (renderIf); a @foreach keeps its iterations, moving, adding and removing
 * them (renderForeach). Fusion's own: @error and @forelse's @empty are branches like @else;
 * @for, @while and @switch render again as a whole when what they read changes (renderDynamic),
 * a @switch only when its case changes; a block a @break leaves is part of the loop it breaks.
 */
import { enter, motionless, transitionOf } from './transition.js';
import * as B from '../../blade.js';
import * as P from '../../php.js';
import { append, createAnchorNode, getAnchor, nextAnchorNodeId, removeBefore, removeNode } from './anchor.js';
import { evaluate } from './call.js';
import { hydrateChild, hydrateComment, hydrateRaw, hydrateTag, hydrateText } from './hydrate.js';
import { renderAttributeValue } from './renderAttributeValue.js';
import { renderDynamic } from './renderDynamic.js';
import { renderForeach, setLoopArguments } from './renderForeach.js';
import { conditionOf, renderIf } from './renderIf.js';
import { renderRaw } from './renderRaw.js';
import { renderText, textContent } from './renderText.js';
import { createScope, dispose, track } from './scope.js';

const SVG = 'http://www.w3.org/2000/svg';
const MATHML = 'http://www.w3.org/1998/Math/MathML';

/** Directives that continue the block before them. */
const CONTINUES = new Set(['else-if', 'else', 'empty']);
/** Blocks a @break ends. */
const LOOPS = new Set(['foreach', 'for', 'while', 'switch']);

/**
 * Returns a @break/@continue on its way out of the loops it leaves ({kind, levels}), or nothing.
 */
export function render(target, instance, nodes, scope, hydrate = true, insert = false) {
    let ifConditions = null;
    for (let i = 0; i < nodes.length; i++) {
        const node = nodes[i];
        switch (node.t) {
            case 'template': {
                let last = i;
                while (nodes[last + 1]?.t === 'template' && CONTINUES.has(nodes[last + 1].i[0].c)) last++;
                const chain = nodes.slice(i, last + 1);
                i = last;
                const signal = renderChain(target, instance, chain, scope, hydrate, insert, ifConditions);
                if (signal?.kind) return signal;
                ifConditions = signal ?? null;
                break;
            }
            case 'x': {
                if (node.raw) {
                    renderRawNode(target, instance, node, scope, hydrate, insert);
                    break;
                }
                /** Fusion: text next to text is one text node (Viewi joins them when it compiles). */
                let last = i;
                while (nodes[last + 1]?.t === 'x' && !nodes[last + 1].raw) last++;
                const group = nodes.slice(i, last + 1);
                i = last;
                const content = textContent(instance, group, scope);
                const textNode = hydrate
                    ? hydrateText(instance, target, content)
                    : append(target, instance.document.createTextNode(content), insert);
                const codes = group.filter((n) => n.e).map((n) => n.code);
                if (codes.length) {
                    track(instance, codes, scope, () => renderText(instance, group, textNode, scope));
                }
                break;
            }
            case 'm': {
                const content = node.c ?? '';
                hydrate
                    ? hydrateComment(instance, target, content)
                    : append(target, instance.document.createComment(content), insert);
                break;
            }
            case 't':
                renderTag(target, instance, node, scope, hydrate, insert);
                break;
            case 'c':
                renderChild(target, instance, node, scope, hydrate, insert);
                break;
            case 's':
                renderSlot(target, instance, node, scope, hydrate, insert);
                break;
            case 'p':
                renderTeleport(target, instance, node, scope, hydrate, insert);
                break;
            case 'b':
                if (node.code === undefined || evaluate(instance, node.code, scope)) {
                    return { kind: node.c, levels: node.levels ?? 1 };
                }
                break;
            default:
                throw new Error(`Fusion: unknown template node ${node.t}`);
        }
    }
    return undefined;
}

// ------------------------------------------------------------ tags

/** A tag (Viewi's tag case and withAttributes). rootEl: the component's root, hydrated in place. */
export function renderTag(target, instance, node, scope, hydrate, insert, rootEl = null) {
    const tag = node.c;
    const ns = namespaceOf(rootEl ? rootEl.parentNode : insert ? target.parentNode : target, tag);
    let element;
    let created;
    if (rootEl) {
        [element, created] = [rootEl, false];
    } else if (hydrate) {
        [element, created] = hydrateTag(instance, target, tag, ns);
    } else {
        element = ns === null ? instance.document.createElement(tag) : instance.document.createElementNS(ns, tag);
        created = true;
    }

    /** Fusion: the component whose view rendered it (its handlers, models, refs), wherever it sits. */
    if (instance.ownerId !== undefined) element.fusionOwner = instance.ownerId;

    /** Fusion: fusion:ignore keeps an element and everything in it as it is. */
    if (!created && element.hasAttribute('fusion:ignore')) return element;

    const root = rootEl !== null;
    renderAttributeValue(instance, node, element, scope, root, hydrate && !created);
    const codes = attributeCodes(node);
    if (codes.length) {
        track(instance, codes, scope, () => renderAttributeValue(instance, node, element, scope, root, false));
    }

    /** Fusion: fusion:<event> handlers, made with the variables the element renders with. */
    for (const attribute of node.a ?? []) {
        if (!attribute.on) continue;
        const bind = () => instance.host.on(element, attribute.on.e, attribute.on.m ?? [], evaluate(instance, attribute.on.code, scope));
        bind();
        track(instance, [attribute.on.code], scope, bind);
    }

    let childScope = scope;
    if (created && element.hasAttribute('fusion:ignore')) {
        childScope = createScope(scope, 'ignore');
        childScope.untracked = true;
    }

    if (tag === 'textarea' && ns === null) {
        renderTextarea(instance, element, node, childScope, created);
    } else {
        render(tag === 'template' && ns === null ? element.content : element, instance, node.h ?? [], childScope, hydrate && !created, false);
    }

    if (created && !hydrate) {
        append(target, element, insert);
        instance.host.added?.(element);

        /** fusion:transition: it enters animated, once the component is live (on its first render too with .appear). */
        const transition = transitionOf(element);
        if (transition?.enter && (instance.live || transition.appear) && !motionless(element)) enter(element, transition);
    }
    return element;
}

/** The namespace a tag is made in (null: HTML); SVG's <foreignObject> holds HTML again. */
function namespaceOf(parent, tag) {
    if (tag === 'svg') return SVG;
    if (tag === 'math') return MATHML;
    const ns = parent?.namespaceURI;
    if (ns === SVG && parent.localName !== 'foreignObject') return SVG;
    if (ns === MATHML) return MATHML;
    return null;
}

/** Fusion: a <textarea>'s text is its initial value; after that the value is what it shows. */
function renderTextarea(instance, element, node, scope, created) {
    const content = () => {
        const scratch = instance.document.createElement('div');
        const scratchScope = createScope(scope, 'textarea');
        scratchScope.untracked = true;
        render(scratch, instance, node.h ?? [], scratchScope, false, false);
        dispose(instance, scratchScope);
        return scratch.textContent;
    };
    const show = (text) => {
        if (element.value !== text && element !== instance.document.activeElement) element.value = text;
    };
    const text = content();
    created ? (element.textContent = text) : show(text);
    track(instance, codesIn(node.h ?? []), scope, () => show(content()));
}

// ------------------------------------------------------------ children, raw HTML

/** Fusion: <fusion:name />, a placeholder the runtime puts the child component in (it renders itself). */
function renderChild(target, instance, node, scope, hydrate, insert) {
    if (node.is !== undefined) {
        renderDynamicChild(target, instance, node, scope, hydrate, insert);
        return;
    }
    placeChild(target, instance, node, scope, hydrate, insert, node.c);
}

/**
 * Viewi's dynamic tag (render.ts, renderDynamic.ts), as Livewire's <fusion:dynamic-component
 * :is="$name">: the component the expression names, between anchors; when it names another, what
 * was rendered goes (its scope disposed, its nodes removed) and that one renders. Fusion: the
 * same name keeps its component (a component has state; Viewi renders anew).
 */
function renderDynamicChild(target, instance, node, scope, hydrate, insert) {
    const anchor = hydrate ? getAnchor(instance, target) : undefined;
    const anchorBegin = createAnchorNode(instance, target, insert, anchor); // begin dynamic
    const container = { scope: createScope(scope, 'dynamic'), name: evaluate(instance, node.is, scope), anchorNode: null };
    if (container.name !== '') placeChild(target, instance, node, container.scope, hydrate, insert, container.name);
    container.anchorNode = createAnchorNode(instance, target, insert, anchor, anchorBegin._anchor); // end dynamic
    track(instance, [node.is], scope, () => {
        const name = evaluate(instance, node.is, scope);
        if (name === container.name) return;
        dispose(instance, container.scope);
        removeBefore(instance, container.anchorNode);
        container.scope = createScope(scope, 'dynamic');
        container.name = name;
        if (name !== '') placeChild(container.anchorNode, instance, node, container.scope, false, true, name);
    });
}

/** The child's placeholder, and its props followed. A dynamic child's key has its name: another name, another component. */
function placeChild(target, instance, node, scope, hydrate, insert, name) {
    const key = (node.key !== undefined
        ? evaluate(instance, node.key, scope)
        : 'c' + node.n + (scope.loops.length ? '.' + scope.loops.join('.') : '')) + (node.is !== undefined ? ':' + name : '');
    /** Its slots: this view's nodes, to render inside the child with this instance and scope (Viewi's). */
    const slots = {};
    for (const [slotName, slot] of Object.entries(node.slots ?? {})) {
        slots[slotName] = { nodes: slot.h ?? [], instance, scope };
    }
    const id = instance.host.child(name, key, evaluate(instance, node.props, scope), slots, node.model);
    if (id === null || id === undefined) return;

    if (hydrate) {
        hydrateChild(instance, target, id);
    } else {
        const placeholder = instance.document.createElement('div');
        placeholder.setAttribute('fusion:id', id);
        placeholder.setAttribute('fusion:placeholder', '');
        append(target, placeholder, insert);
    }
    track(instance, [node.props], scope, () => instance.host.child(name, key, evaluate(instance, node.props, scope), undefined, node.model));
}

/**
 * Viewi's slot: {{ $slot }} / {{ $slots['footer'] }} in a child's view. The parent's slot nodes
 * render here with the parent's instance, in a scope of the parent's (its state, its variables,
 * its handlers): they follow the parent's changes; this child's region only places them.
 */
function renderSlot(target, instance, node, scope, hydrate, insert) {
    const anchor = hydrate ? getAnchor(instance, target) : undefined;
    const anchorBegin = createAnchorNode(instance, target, insert, anchor); // begin slot
    const region = { name: node.c, foreign: null, anchorNode: null };
    fillSlot(target, instance, region, hydrate, insert);
    region.anchorNode = createAnchorNode(instance, target, insert, anchor, anchorBegin._anchor); // end slot
    instance.slotRegions.add(region);
    scope.cleanups.push(() => {
        instance.slotRegions.delete(region);
        disposeSlot(region);
    });
}

function fillSlot(target, instance, region, hydrate, insert) {
    const provided = instance.host.slot?.(region.name);
    if (!provided) return;
    /** HTML from a Blade view (a component placed there has no parent in the browser): as raw HTML. */
    if (provided.html !== undefined) {
        const vdom = instance.document.createElement(rawParentName(insert ? target.parentNode : target));
        vdom.innerHTML = provided.html;
        if (hydrate) {
            const anchor = getAnchor(instance, target);
            anchor.current++;
            hydrateRaw(vdom, anchor, target);
            anchor.current--;
        } else {
            for (const node of [...vdom.childNodes]) append(target, node, insert);
        }
        return;
    }
    const slotScope = createScope(provided.scope, 'slot');
    region.foreign = [provided.instance, slotScope];
    /** Hydrating: the parent's nodes walk this child's HTML, at this child's positions. */
    const anchors = provided.instance.anchors;
    provided.instance.anchors = instance.anchors;
    try {
        render(target, provided.instance, provided.nodes, slotScope, hydrate, insert);
    } finally {
        provided.instance.anchors = anchors;
    }
}

function disposeSlot(region) {
    if (region.foreign) dispose(...region.foreign);
    region.foreign = null;
}

/** The parent gave new slots (it rendered the child again): each slot region renders them. */
export function refreshSlots(instance) {
    for (const region of instance.slotRegions) {
        disposeSlot(region);
        removeBefore(instance, region.anchorNode);
        fillSlot(region.anchorNode, instance, region, false, true);
    }
}

/**
 * Viewi's <Portal to="name"> (core/portal/portal.ts, renderPortal.ts), as Livewire's
 * @teleport('name'): nothing renders in place; the content renders before the end mark of
 * @portal('name') (Viewi's <Portal name="...">), with this view's instance, in a scope of this
 * one. The server put it there: hydrating, it is taken over where it is.
 */
function renderTeleport(target, instance, node, scope, hydrate, insert) {
    const portal = { to: node.to, anchorNode: null };
    renderPortal(instance, portal, node.h ?? [], createScope(scope, 'portal'), hydrate, !hydrate);
    instance.teleports.add(portal);
    scope.cleanups.push(() => {
        instance.teleports.delete(portal);
        destroyPortal(instance, portal);
    });
}

/** Viewi's renderPortal. */
function renderPortal(instance, portal, nodes, scope, hydrate = false, insert = false) {
    const document = instance.document;
    const portalEndMark = document.getElementById('portal_' + portal.to + '_end');
    if (portalEndMark) { // Viewi: TODO: delay render when portal is not ready
        const portalBeginMark = document.getElementById('portal_' + portal.to);
        /** Viewi's portals[name].current, set as the name portal hydrates; Fusion's layout is Blade: from its begin mark. */
        portalBeginMark.fusionPortalCurrent ??= Array.prototype.indexOf.call(portalBeginMark.parentNode.childNodes, portalBeginMark);
        const renderTarget = insert ? portalEndMark : portalEndMark.parentElement;
        const owned = instance.anchors?.has(renderTarget);
        const anchor = hydrate ? getAnchor(instance, renderTarget) : undefined;
        /** Fusion: the view does not own the portal's element (the layout does): hydration leaves the rest of it. */
        if (hydrate && !owned) anchor.foreign = true;
        const anchorCurrent = hydrate ? anchor.current : 0;
        const portalPositionIndexBefore = Array.prototype.indexOf.call(renderTarget.childNodes, portalEndMark);
        hydrate && (anchor.current = portalBeginMark.fusionPortalCurrent);
        // render
        const anchorSlotBegin = createAnchorNode(instance, renderTarget, insert, anchor); // begin slot
        render(renderTarget, instance, nodes, scope, hydrate, insert);
        const anchorSlotNode = createAnchorNode(instance, renderTarget, insert, anchor, anchorSlotBegin._anchor); // end slot
        portal.anchorNode = anchorSlotNode;
        // restore anchor position
        if (hydrate) {
            portalBeginMark.fusionPortalCurrent = anchor.current;
            const portalPositionIndexAfter = Array.prototype.indexOf.call(renderTarget.childNodes, portalEndMark);
            anchor.current = anchorCurrent + portalPositionIndexAfter - portalPositionIndexBefore;
        }
    } else {
        console.warn(`Fusion: @teleport('${portal.to}') found no @portal('${portal.to}') in the page`);
    }
}

/** Viewi's Portal.destroy: its nodes, and its anchors, leave the page (Fusion: the components in them too). */
function destroyPortal(instance, portal) {
    if (portal.anchorNode && portal.anchorNode.previousSibling) {
        while (portal.anchorNode.previousSibling._anchor !== portal.anchorNode._anchor) {
            removeNode(instance, portal.anchorNode.previousSibling);
        }
        portal.anchorNode.previousSibling.remove();
        portal.anchorNode.remove();
    }
}

/** The top elements of the view's teleported content (they sit elsewhere in the page). */
export function teleported(instance) {
    const elements = [];
    for (const portal of instance.teleports) {
        for (let node = portal.anchorNode?.previousSibling; node && node._anchor !== portal.anchorNode._anchor; node = node.previousSibling) {
            if (node.nodeType === 1) elements.unshift(node);
        }
    }
    return elements;
}

/** Whether the parent passed the slot, with content (not only whitespace). */
export function slotHasContent(provided) {
    if (provided?.html !== undefined) return provided.html.trim() !== '';
    return !!provided && provided.nodes.some((n) => n.t !== 'x' || n.e || (n.c ?? '').trim() !== '');
}

/** Viewi's raw text: {!! $html !!}, parsed in an element like the one it lands in. */
function renderRawNode(target, instance, node, scope, hydrate, insert) {
    const parentTagNode = insert ? target.parentNode : target;
    const vdom = instance.document.createElement(rawParentName(parentTagNode));
    vdom.innerHTML = evaluate(instance, node.code, scope) ?? '';
    const anchor = hydrate ? getAnchor(instance, target) : undefined;
    const anchorBegin = createAnchorNode(instance, target, insert, anchor); // begin raw
    if (hydrate) {
        anchor.current++;
        hydrateRaw(vdom, anchor, target);
        /** Fusion: back on the last node it took; Viewi's end anchor went one node too far (the counter fixture's raw HTML: the text after it came out twice). */
        anchor.current--;
    } else {
        for (const rawNode of [...vdom.childNodes]) append(target, rawNode, insert);
    }
    const anchorNode = createAnchorNode(instance, target, insert, anchor, anchorBegin._anchor); // end raw
    track(instance, [node.code], scope, () => renderRaw(instance, node, scope, anchorNode));
}

/**
 * The element raw HTML is parsed in (Viewi: one named as its parent). Fusion: a new component's
 * nodes are made in a fragment first, which has no element name: a <div> parses as its parent will.
 */
export function rawParentName(parent) {
    return parent?.nodeType === 1 ? parent.nodeName : 'div';
}

// ------------------------------------------------------------ blocks

/**
 * A block and those continuing it (@elseif, @else, @empty). Returns a @break/@continue leaving
 * it, or the conditions a following @else continues from.
 */
function renderChain(target, instance, chain, scope, hydrate, insert, ifConditions) {
    const first = chain[0].i[0].c;

    /** Fusion: untracked (fusion:ignore), or a block a @break leaves: part of what is around it. */
    if (scope.untracked || chain.some((node) => leak(instance, node) > 0)) {
        return renderBranches(target, instance, chain, scope, hydrate, insert);
    }

    /** Fusion: a loop a @break ends, @for, @while and @switch render again as a whole. */
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
                    console.warn(`Fusion: @${directive.c} has no block before it`, directive);
                    break;
                }
                renderCondition(target, instance, node, scope, hydrate, insert, ifConditions);
                break;
            case 'foreach':
                ifConditions = renderForeachBlock(target, instance, node, scope, hydrate, insert);
                break;
            default:
                throw new Error(`Fusion: unknown directive ${directive.c}`);
        }
    }
    return ifConditions;
}

/** Viewi's if / else-if / else (and Fusion's @error, @empty): a branch between anchors. */
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
    /** Fusion: a Map keeps PHP's order and its keys' types (Viewi keeps an object). */
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

    /** Fusion: @forelse's @empty continues from whether there were items. */
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
                throw new Error(`Fusion: unknown directive ${directive.c}`);
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
    if (directive.c === 'error') codes.push('$errors');
    if (directive.c === 'switch') {
        for (const c of node.h ?? []) if (c.i[0].code !== undefined) codes.push(c.i[0].code);
    }
    return codes;
}

/** The expressions of an element's attribute values (handlers are bound on their own). */
function attributeCodes(node) {
    const codes = [];
    for (const attribute of node.a ?? []) {
        if (attribute.when !== undefined) codes.push(attribute.when);
        for (const part of attribute.h ?? []) if (part.e) codes.push(part.code);
    }
    return codes;
}

/** Every expression in the nodes. */
function codesIn(nodes) {
    const codes = [];
    for (const node of nodes) {
        if (node.t === 'x' && node.e) codes.push(node.code);
        if (node.t === 't') codes.push(...attributeCodes(node));
        if (node.t === 'c') codes.push(node.props, ...(node.key !== undefined ? [node.key] : []), ...(node.is !== undefined ? [node.is] : []));
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
