/**
 * Viewi's anchors (core/anchor): empty text nodes that mark where a block's nodes begin and end
 * (a block's begin and end share a name), and, while the server's HTML is hydrated, how far into
 * each element the render has matched (getAnchor).
 */
import { leaving, motionless, transitionOf } from './transition.js';

let anchorNodeId = 0;

export function nextAnchorNodeId() {
    return ++anchorNodeId;
}

/** Viewi's getAnchor: the hydration position in the target (Fusion: kept by the hydration, not on the node). */
export function getAnchor(instance, target) {
    let anchor = instance.anchors.get(target);
    if (!anchor) {
        anchor = { current: -1, target, invalid: [], added: 0 };
        instance.anchors.set(target, anchor);
    }
    return anchor;
}

/** Viewi's createAnchorNode: at the hydration position, before the target (insert), or at its end. */
export function createAnchorNode(instance, target, insert = false, anchor = undefined, name = undefined) {
    const anchorNode = instance.document.createTextNode('');
    anchorNode._anchor = name ?? '#' + ++anchorNodeId;
    if (anchor) {
        anchor.current++;
    }
    (insert || (anchor && target.childNodes.length > anchor.current))
        ? (anchor ? target : target.parentNode).insertBefore(anchorNode, anchor ? target.childNodes[anchor.current] : target)
        : target.appendChild(anchorNode);
    return anchorNode;
}

/** The nodes between a block's begin anchor and its end anchor, removed (Viewi's remove loops). */
export function removeBefore(instance, anchorNode) {
    /** Gathered first: a node that leaves animated stays in the page meanwhile. */
    const nodes = [];
    for (let node = anchorNode.previousSibling; node && node._anchor !== anchorNode._anchor; node = node.previousSibling) {
        nodes.push(node);
    }
    nodes.forEach((node) => removeNode(instance, node));
}

/** Fusion: a node leaving the page takes the components in it along. */
export function removeNode(instance, node) {
    instance.host.removed?.(node);

    /** fusion:transition: it leaves animated, then goes. */
    const transition = transitionOf(node);
    if (transition?.leave && node.isConnected && !motionless(node)) {
        if (node._leaving) return;
        node._leaving = true;
        leaving(node, transition).then(() => node.remove(), () => node.remove());
        return;
    }
    node.remove();
}

/** Where a node goes: before the target (insert) or at its end. */
export function append(target, node, insert) {
    return insert ? target.parentNode.insertBefore(node, target) : target.appendChild(node);
}
