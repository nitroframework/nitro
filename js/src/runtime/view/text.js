/**
 * Viewi's renderText (core/render/renderText). Nitro: text next to text is one text node, as the
 * HTML parser makes it (Viewi joins them when it compiles), so a text binding renders a group.
 */
import { callArguments } from './call.js';
import { setFullText, trimLimits } from './trim.js';

/** The group's parts as they render ({text, ls, ts}: a static part's literal whitespace, trim.js trimLimits()). */
export function textParts(instance, group, scope) {
    return group.map((node) => {
        if (!node.e) return { text: node.c ?? '', ls: node.ls, ts: node.ts };
        const value = instance.$$t[node.code].apply(null, callArguments(instance, scope));
        return { text: String(node.d ? decoded(instance.document, value, false) : value) };
    });
}

/** How much of a text node's text, made of the parts, Blade's trims can take (trim.js). */
export function setLimits(textNode, parts) {
    const limits = trimLimits(parts);
    if (limits === undefined) delete textNode._limits;
    else textNode._limits = limits;
}

/**
 * Output Blade prints as it is, in <textarea> / <title> or an attribute's value (d): its
 * character references decoded by the HTML parser itself, as the server's HTML is (an
 * attribute's own rules: a value with a quote in it ends the attribute there, as on the server,
 * so it is read as text).
 */
export function decoded(document, value, inAttribute) {
    const text = String(value);
    if (!text.includes('&')) return text;
    if (inAttribute && !text.includes('"')) {
        const template = document.createElement('template');
        template.innerHTML = '<i a="' + text + '"></i>';
        return template.content.firstChild.getAttribute('a');
    }
    const textarea = document.createElement('textarea');
    textarea.innerHTML = text;
    return textarea.value;
}

export function renderText(instance, group, textNode, scope) {
    if (scope.disposed) {
        return;
    }
    const parts = textParts(instance, group, scope);
    const content = parts.map((part) => part.text).join('');
    /** Its full text: a region Blade trims may trim it again (trim.js, after the update). */
    setFullText(textNode, content);
    setLimits(textNode, parts);
    textNode.nodeValue !== content && (textNode.nodeValue = content);
}
