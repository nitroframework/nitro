/**
 * Viewi's hydration (core/hydrate): the render walks the server's HTML as it goes, taking the
 * node at the next position (or the one after) when it is what the template has there, and
 * creating it when it is not. What it skips is invalid; cleanup() removes that, and whatever is
 * left after the last position, once the hydration is done (Viewi's renderApp).
 */
import { getAnchor } from './anchor.js';

/** Viewi's hydrateTag (Fusion: the namespace given, since SVG names keep their case). */
export function hydrateTag(instance, target, tag, ns) {
    const anchor = getAnchor(instance, target);
    const max = target.childNodes.length;
    let end = anchor.current + 3;
    end = end > max ? max : end;
    const invalid = [];
    for (let i = anchor.current + 1; i < end; i++) {
        const potentialNode = target.childNodes[i];
        if (
            potentialNode.nodeType === 1
            && potentialNode.localName.toLowerCase() === tag.toLowerCase()
        ) {
            anchor.current = i;
            anchor.invalid = anchor.invalid.concat(invalid);
            return [potentialNode, false];
        }
        invalid.push(i);
    }
    anchor.added++;
    anchor.invalid = anchor.invalid.concat(invalid);
    const element = ns === null ? instance.document.createElement(tag) : instance.document.createElementNS(ns, tag);
    anchor.current = anchor.current + invalid.length + 1;
    return [
        max > anchor.current
            ? target.insertBefore(element, target.childNodes[anchor.current])
            : target.appendChild(element),
        true,
    ];
}

/** Fusion: a child component's element (it hydrates itself), found by its id. */
export function hydrateChild(instance, target, id) {
    const anchor = getAnchor(instance, target);
    const max = target.childNodes.length;
    let end = anchor.current + 3;
    end = end > max ? max : end;
    const invalid = [];
    for (let i = anchor.current + 1; i < end; i++) {
        const potentialNode = target.childNodes[i];
        if (potentialNode.nodeType === 1 && potentialNode.getAttribute('fusion:id') === id) {
            anchor.current = i;
            anchor.invalid = anchor.invalid.concat(invalid);
            return potentialNode;
        }
        invalid.push(i);
    }
    anchor.added++;
    anchor.invalid = anchor.invalid.concat(invalid);
    const placeholder = instance.document.createElement('div');
    placeholder.setAttribute('fusion:id', id);
    placeholder.setAttribute('fusion:placeholder', '');
    anchor.current = anchor.current + invalid.length + 1;
    return max > anchor.current
        ? target.insertBefore(placeholder, target.childNodes[anchor.current])
        : target.appendChild(placeholder);
}

/** Viewi's hydrateText. */
export function hydrateText(instance, target, content) {
    const anchor = getAnchor(instance, target);
    const max = target.childNodes.length;
    let end = anchor.current + 3;
    end = end > max ? max : end;
    const invalid = [];
    const start = anchor.current > -1 ? anchor.current : anchor.current + 1;
    for (let i = start; i < end; i++) {
        const potentialNode = target.childNodes[i];
        if (
            potentialNode.nodeType === 3
        ) {
            if (i === anchor.current) {
                // text after text, no shift
                break;
            }
            anchor.current = i;
            anchor.invalid = anchor.invalid.concat(invalid);
            potentialNode.nodeValue !== content && (potentialNode.nodeValue = content);
            return potentialNode;
        }
        i !== anchor.current && invalid.push(i);
    }
    anchor.added++;
    anchor.invalid = anchor.invalid.concat(invalid);
    const textNode = instance.document.createTextNode(content);
    anchor.current = anchor.current + invalid.length + 1;
    return max > anchor.current
        ? target.insertBefore(textNode, target.childNodes[anchor.current])
        : target.appendChild(textNode);
}

/** Viewi's hydrateComment. */
export function hydrateComment(instance, target, content) {
    const anchor = getAnchor(instance, target);
    const max = target.childNodes.length;
    let end = anchor.current + 3;
    end = end > max ? max : end;
    const invalid = [];
    for (let i = anchor.current + 1; i < end; i++) {
        const potentialNode = target.childNodes[i];
        if (potentialNode.nodeType === 8) {
            anchor.current = i;
            anchor.invalid = anchor.invalid.concat(invalid);
            potentialNode.nodeValue !== content && (potentialNode.nodeValue = content);
            return potentialNode;
        }
        invalid.push(i);
    }
    anchor.added++;
    anchor.invalid = anchor.invalid.concat(invalid);
    const element = instance.document.createComment(content);
    anchor.current = anchor.current + invalid.length + 1;
    return max > anchor.current
        ? target.insertBefore(element, target.childNodes[anchor.current])
        : target.appendChild(element);
}

/** Viewi's hydrateRaw: {!! $html !!} parsed (vdom), matched node by node. */
export function hydrateRaw(vdom, anchor, target) {
    if (vdom.childNodes.length > 0) {
        const invalid = [];
        const rawNodes = Array.prototype.slice.call(vdom.childNodes);
        for (let rawNodeI = 0; rawNodeI < rawNodes.length; rawNodeI++) {
            const rawNode = rawNodes[rawNodeI];
            const rawNodeType = rawNode.nodeType;
            if (rawNodeType === 3) {
                // text
                const currentTargetNode = target.childNodes[anchor.current];
                if (currentTargetNode && currentTargetNode.nodeType === rawNodeType) {
                    currentTargetNode.nodeValue = rawNode.nodeValue;
                } else {
                    anchor.added++;
                    target.childNodes.length > anchor.current && invalid.push(anchor.current);
                    target.childNodes.length > anchor.current + 1
                        ? target.insertBefore(rawNode, target.childNodes[anchor.current + 1])
                        : target.appendChild(rawNode);
                    anchor.current++;
                }
            } else {
                // other
                const currentTargetNode = target.childNodes[anchor.current];
                if (
                    !currentTargetNode
                    || currentTargetNode.nodeType !== rawNodeType
                    || (rawNodeType === 1 && currentTargetNode.nodeName !== rawNode.nodeName)
                ) {
                    anchor.added++;
                    target.childNodes.length > anchor.current && invalid.push(anchor.current);
                    target.childNodes.length > anchor.current + 1
                        ? target.insertBefore(rawNode, target.childNodes[anchor.current + 1])
                        : target.appendChild(rawNode);
                    anchor.current++;
                } else if (rawNodeType === 1) {
                    if (currentTargetNode.outerHTML !== rawNode.outerHTML) {
                        currentTargetNode.outerHTML = rawNode.outerHTML;
                    }
                }
                // matched, continue
            }
            anchor.current++;
        }
        if (invalid.length > 0) {
            anchor.invalid = anchor.invalid.concat(invalid);
        }
    }
}

/**
 * Viewi's clean up of unhydrated content: what is left after each element's last matched node,
 * and the nodes skipped. (Fusion: the component's own; a child component's element is matched,
 * never left.)
 */
export function cleanup(instance) {
    for (const anchor of instance.anchors.values()) {
        const target = anchor.target;
        /** Fusion: a portal's element is the layout's (Blade's, not a view's): what follows is not the view's to remove. */
        for (let i = target.childNodes.length - 1; !anchor.foreign && i >= anchor.current + 1; i--) {
            instance.host.removed?.(target.childNodes[i]);
            target.childNodes[i].remove();
        }
        for (let i = anchor.invalid.length - 1; i >= 0; i--) {
            const node = target.childNodes[anchor.invalid[i]];
            if (node) {
                instance.host.removed?.(node);
                node.remove();
            }
        }
    }
}
