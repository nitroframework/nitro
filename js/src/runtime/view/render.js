/**
 * Viewi's render (core/render/render.ts), for Nitro's templates: render nodes into a target
 * element, hydrating the server's HTML (hydrate) or making new nodes, at the target's end or
 * before it (insert: the target is an anchor), and track what each part reads.
 *
 *   node.t: "t" tag, "x" text, "m" comment, "c" child component, "b" @break/@continue,
 *           "template" a Blade block, its directive in i (Viewi puts directives on the tag;
 *           Blade's wrap any nodes, as Viewi's <template if="...">)
 *
 * Blocks, as Viewi's: an @if chain renders a branch between anchors, again only when which
 * branch it is changes (renderIf); a @foreach keeps its iterations, moving, adding and removing
 * them (renderForeach). Nitro's own: @error and @forelse's @empty are branches like @else;
 * @for, @while and @switch render again as a whole when what they read changes (renderDynamic),
 * a @switch only when its case changes; a block a @break leaves is part of the loop it breaks.
 */
import { entering, motionless, transitionOf } from './transition.js';
import { append } from './anchor.js';
import { evaluate } from './call.js';
import { hydrateComment, hydrateTag, hydrateText } from './hydrate.js';
import { renderAttributeValue } from './attributes.js';
import { renderText, textContent } from './text.js';
import { createScope, dispose, track } from './scope.js';
import { renderChild, renderRawNode, renderSlot, renderTeleport } from './children.js';
import { attributeCodes, codesIn, renderChain } from './blocks.js';
export { rawParentName, refreshSlots, slotHasContent, teleported } from './children.js';
export { directiveCodes } from './blocks.js';

const SVG = 'http://www.w3.org/2000/svg';
const MATHML = 'http://www.w3.org/1998/Math/MathML';

/** Directives that continue the block before them. */
const CONTINUES = new Set(['else-if', 'else', 'empty']);
/** Blocks a @break ends. */
export const LOOPS = new Set(['foreach', 'for', 'while', 'switch']);

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
                /** Nitro: text next to text is one text node (Viewi joins them when it compiles). */
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
                /** Clone mode (nitro.rendering): a static part, copied rather than created. */
                node.s && !hydrate ? cloneStatic(target, instance, node, scope, insert) : renderTag(target, instance, node, scope, hydrate, insert);
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
                throw new Error(`Nitro: unknown template node ${node.t}`);
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

    /** Nitro: the component whose view rendered it (its handlers, models, refs), wherever it sits. */
    if (instance.ownerId !== undefined) element.nitroOwner = instance.ownerId;

    /**
     * Nitro: nitro:ignore: the element and what is in it start once, as the server rendered them or
     * as made here (their handlers, their app's nitro: attributes), and no render changes them after:
     * a library that owns that markup keeps it.
     */
    /** From the view: a new element has no attributes yet. */
    const ignored = (node.a ?? []).some((attribute) => attribute.c === 'nitro:ignore');
    /** Live already (a list taking up its rows again): left as it is. */
    if (ignored && !created && instance.live) return element;
    let childScope = scope;
    if (ignored) {
        childScope = createScope(scope, 'ignore');
        childScope.untracked = true;
    }

    renderAttributeValue(instance, node, element, scope);
    const codes = attributeCodes(node);
    if (codes.length && !ignored) {
        track(instance, codes, scope, () => renderAttributeValue(instance, node, element, scope));
    }

    /** Nitro: nitro:<event> handlers, made with the variables the element renders with. */
    for (const attribute of node.a ?? []) {
        if (!attribute.on) continue;
        const bind = () => instance.host.on(element, attribute.on.e, attribute.on.m ?? [], evaluate(instance, attribute.on.code, scope), attribute.c);
        bind();
        if (!ignored) track(instance, [attribute.on.code], scope, bind);
    }

    if (tag === 'textarea' && ns === null) {
        renderTextarea(instance, element, node, childScope, created);
    } else {
        render(tag === 'template' && ns === null ? element.content : element, instance, node.h ?? [], childScope, hydrate && !created, false);
    }

    if (created && !hydrate) {
        append(target, element, insert);
        instance.host.added?.(element);

        /** nitro:transition: it enters animated, once the component is live (on its first render too with .appear). */
        const transition = transitionOf(element);
        if (transition?.enter && (instance.live || transition.appear) && !motionless(element)) entering(element, transition);
    }
    return element;
}

/**
 * Clone mode (config nitro.rendering = 'clone', the default; the compiler marks static parts s: 1, see
 * StaticParts.php): a part without data, copied. The first time it renders (per namespace it lands
 * in) it is made as create mode makes it, into a detached element, with no component's marks; that
 * one is kept, and every render copies it. The copy then gets what create mode gives a new element
 * of the component: its owner (read by walking up, so on the part's top element) and added().
 */
const statics = new WeakMap();

function cloneStatic(target, instance, node, scope, insert) {
    const ns = namespaceOf(insert ? target.parentNode : target, node.c);
    let byNs = statics.get(node);
    if (!byNs) statics.set(node, (byNs = new Map()));
    let source = byNs.get(ns);
    if (!source) {
        const document = instance.document;
        const holder = ns === null ? document.createElement('div') : document.createElementNS(ns, ns === SVG ? 'svg' : 'math');
        /** No component: no owner, no handlers, nothing tracked (a static part has none of those). */
        const neutral = { document, host: {}, leaks: new WeakMap() };
        source = renderTag(holder, neutral, node, scope, false, false);
        byNs.set(ns, source);
    }

    const element = source.cloneNode(true);
    if (instance.ownerId !== undefined) element.nitroOwner = instance.ownerId;
    append(target, element, insert);
    instance.host.added?.(element);
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

/** Nitro: a <textarea>'s text is its initial value; after that the value is what it shows. */
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
