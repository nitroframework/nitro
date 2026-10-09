/**
 * Viewi's renderText (core/render/renderText). Nitro: text next to text is one text node, as the
 * HTML parser makes it (Viewi joins them when it compiles), so a text binding renders a group.
 */
import { callArguments } from './call.js';

export function textContent(instance, group, scope) {
    let content = '';
    for (const node of group) {
        content += node.e ? instance.$$t[node.code].apply(null, callArguments(instance, scope)) : node.c ?? '';
    }
    return content;
}

export function renderText(instance, group, textNode, scope) {
    if (scope.disposed) {
        return;
    }
    const content = textContent(instance, group, scope);
    textNode.nodeValue !== content && (textNode.nodeValue = content);
}
