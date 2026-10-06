/** Children, slots, portals (@teleport) and raw HTML, as render() renders them. Part of render.js. */
import { append, createAnchorNode, getAnchor, removeBefore, removeNode } from './anchor.js';
import { evaluate } from './call.js';
import { hydrateChild, hydrateRaw } from './hydrate.js';
import { renderRaw } from './raw.js';
import { createScope, dispose, track } from './scope.js';
import { render } from './render.js';

// ------------------------------------------------------------ children, raw HTML

/** Fusion: <fusion:name />, a placeholder the runtime puts the child component in (it renders itself). */
export function renderChild(target, instance, node, scope, hydrate, insert) {
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
    /** fusion:model's path: as written, or built by an expression ("lines.{{ $i }}.qty"). */
    const model = () => typeof node.model === 'number' ? evaluate(instance, node.model, scope) : node.model;
    const id = instance.host.child(name, key, evaluate(instance, node.props, scope), slots, model());
    if (id === null || id === undefined) return;

    if (hydrate) {
        hydrateChild(instance, target, id);
    } else {
        const placeholder = instance.document.createElement('div');
        placeholder.setAttribute('fusion:id', id);
        placeholder.setAttribute('fusion:placeholder', '');
        append(target, placeholder, insert);
    }
    const followed = typeof node.model === 'number' ? [node.props, node.model] : [node.props];
    track(instance, followed, scope, () => instance.host.child(name, key, evaluate(instance, node.props, scope), undefined, model()));
}

/**
 * Viewi's slot: {{ $slot }} / {{ $slots['footer'] }} in a child's view. The parent's slot nodes
 * render here with the parent's instance, in a scope of the parent's (its state, its variables,
 * its handlers): they follow the parent's changes; this child's region only places them.
 */
export function renderSlot(target, instance, node, scope, hydrate, insert) {
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
export function renderTeleport(target, instance, node, scope, hydrate, insert) {
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
export function renderRawNode(target, instance, node, scope, hydrate, insert) {
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
