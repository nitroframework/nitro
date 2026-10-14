/**
 * Blade trims what views render, as strings (PhpEngine::evaluatePath() ltrim()s a view's output;
 * a component's slot is trim()med, ManagesComponents): an included view, an @each item and a
 * Blade component's view start without the whitespace they render first; a slot's content has
 * none at either end. What the compiler cannot see (a view starting with a @foreach, a slot ending
 * in an @if) is trimmed here, in the DOM, after each render.
 *
 * A region is a begin and an end anchor; its start (and, for a slot, its end) is trimmed: the
 * text nodes from the anchor on lose PHP's whitespace (" \t\n\r\0\x0B") until a character that is
 * not, an element, a comment or the region's other anchor. A text node keeps its full text
 * (_full); normalize() puts every trimmed node back to it, then trims for every region in the
 * page, so regions that meet (a slot at the start of a view) agree whatever their order.
 *
 * So too the HTML parser's own: a newline right after <pre>'s start tag is dropped; when what
 * renders there starts with one, the browser drops it (newlineRegion()).
 */

const WHITESPACE_START = /^[ \t\n\r\0\x0B]+/;
const WHITESPACE_END = /[ \t\n\r\0\x0B]+$/;

/** PHP's ltrim() of a string: the whitespace it begins with, gone (no more than limit characters: the literal whitespace, ls). */
export function ltrim(text, limit = Infinity) {
    const string = String(text);
    const whitespace = string.match(WHITESPACE_START)?.[0].length ?? 0;
    return string.slice(Math.min(whitespace, limit));
}

/**
 * How much of a text node's start and end Blade's trims can take ({start, end}), from the parts
 * it is made of ({text, ls, ts}: a static part whose whitespace there is partly a character
 * reference's, &#32;, gives the literal whitespace it has): undefined when it is all theirs.
 */
export function trimLimits(parts) {
    if (!parts.some((part) => part.ls !== undefined || part.ts !== undefined)) return undefined;
    const reach = (ordered, whitespace, field) => {
        let reached = 0;
        for (const part of ordered) {
            const found = part.text.match(whitespace)?.[0].length ?? 0;
            const allowed = part[field] === undefined ? found : Math.min(found, part[field]);
            reached += allowed;
            if (allowed < part.text.length) break;
        }
        return reached;
    };
    return { start: reach(parts, WHITESPACE_START, 'ls'), end: reach([...parts].reverse(), WHITESPACE_END, 'ts') };
}

const regions = new Set();
const trimmedNodes = new Set();
const newlines = new Set();

/** The newline the HTML parser drops at the start of <pre>, <textarea>, <listing>: one, \r\n as one. */
const LEADING_NEWLINE = /^(\r\n|\r|\n)/;

/** What the HTML parser makes of text right after <pre>'s start tag: without the newline it starts with. */
export function dropLeadingNewline(text) {
    return String(text).replace(LEADING_NEWLINE, '');
}

/**
 * A <pre> (or <listing>) whose content starts with what renders (the compiler's nl): the HTML
 * parser drops a newline that starts it in the server's HTML, so the browser drops it too, for as
 * long as the element's scope lasts.
 */
export function newlineRegion(scope, element) {
    newlines.add(element);
    scope.cleanups.push(() => newlines.delete(element));
}

/**
 * A region rendered (start: its start is trimmed; end: its end too), for as long as its scope lasts.
 */
export function trimRegion(scope, begin, end, both) {
    const region = { begin, end, both };
    regions.add(region);
    scope.cleanups.push(() => regions.delete(region));
    return region;
}

/** A text node rendered again: its full text, which a region may trim. */
export function setFullText(node, content) {
    if (node._full !== undefined) node._full = content;
}

/** Every region's trim, again: after a render, an update, a slot rendered anew. */
export function normalize() {
    for (const node of trimmedNodes) {
        if (node.nodeValue !== node._full) node.nodeValue = node._full;
    }
    trimmedNodes.clear();
    for (const region of regions) {
        trimFrom(region.begin, region.end, 'nextSibling', WHITESPACE_START);
        if (region.both) trimFrom(region.end, region.begin, 'previousSibling', WHITESPACE_END);
    }
    for (const element of newlines) dropNewline(element);
}

/** The element's first text (past anchors and empty text), without the newline it starts with. */
function dropNewline(element) {
    for (let node = element.firstChild; node; node = node.nextSibling) {
        if (node._leaving) continue;
        if (node.nodeType !== 3) return;
        if (node._anchor !== undefined || node.nodeValue === '') continue;
        node._full ??= node.nodeValue;
        const dropped = dropLeadingNewline(node.nodeValue);
        if (dropped !== node.nodeValue) {
            node.nodeValue = dropped;
            trimmedNodes.add(node);
        }
        return;
    }
}

function trimFrom(anchor, other, step, whitespace) {
    for (let node = anchor[step]; node && node !== other; node = node[step]) {
        /** An element leaving (nitro:transition) is no longer what the view renders: past it. */
        if (node._leaving) continue;
        if (node.nodeType !== 3) return;
        if (node._anchor !== undefined) continue;
        node._full ??= node.nodeValue;
        let trimmed = node.nodeValue.replace(whitespace, '');
        /** No further than its literal whitespace (trimLimits()): a character reference's stays, as in Blade's string. */
        const limit = node._limits?.[step === 'nextSibling' ? 'start' : 'end'];
        if (limit !== undefined && node.nodeValue.length - trimmed.length > limit) {
            trimmed = step === 'nextSibling' ? node.nodeValue.slice(limit) : node.nodeValue.slice(0, node.nodeValue.length - limit);
        }
        if (trimmed !== node.nodeValue) {
            node.nodeValue = trimmed;
            trimmedNodes.add(node);
        }
        if (trimmed !== '') return;
    }
}
