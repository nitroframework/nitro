/**
 * Viewi's renderRaw (core/render/renderRaw): {!! $html !!} parsed again between its anchors.
 */
import { inPlace, removeBefore } from './anchor.js';
import { evaluate } from './call.js';
import { rawParentName } from './render.js';

export function renderRaw(instance, node, scope, anchorNode) {
    if (scope.disposed || !inPlace(anchorNode)) {
        return;
    }
    // remove
    removeBefore(instance, anchorNode);
    // insert new content
    const parentTagNode = anchorNode.parentNode;
    const vdom = instance.document.createElement(rawParentName(parentTagNode));
    vdom.innerHTML = evaluate(instance, node.code, scope) ?? '';
    const rawNodes = Array.prototype.slice.call(vdom.childNodes);
    for (let rawNodeI = 0; rawNodeI < rawNodes.length; rawNodeI++) {
        const rawNode = rawNodes[rawNodeI];
        parentTagNode.insertBefore(rawNode, anchorNode);
    }
}
