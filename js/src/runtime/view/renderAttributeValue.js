/**
 * Viewi's renderAttributeValue (core/render/renderAttributeValue), for all of an element's
 * attributes at once. Fusion: as the HTML parser does, a repeated attribute keeps its first value
 * (Viewi joins repeated attributes when it compiles); @checked, @disabled, ... are there or not
 * (when); fusion:<event> marks the element for its handler. Attributes set the initial state of
 * inputs; their properties are what the page shows, so those follow.
 */
import { modelAttribute } from '../model.js';
import { evaluate } from './call.js';

/** The attributes the runtime puts on a component's root element: its render keeps them. */
export const RUNTIME_ATTRIBUTES = new Set(['fusion:id', 'fusion:name', 'fusion:page', 'fusion:snapshot']);

const HTML = 'http://www.w3.org/1999/xhtml';
const XLINK = 'http://www.w3.org/1999/xlink';

/** The element's attributes: name => value. */
export function attributeValues(instance, node, scope) {
    const values = new Map();

    for (const attribute of node.a ?? []) {
        if (attribute.on) values.set(`fusion:h-${attribute.on.e}`, '');

        let valueContent = '';
        if (attribute.when !== undefined) {
            if (!evaluate(instance, attribute.when, scope)) continue;
        } else if (attribute.h) {
            for (const attributeValue of attribute.h) {
                valueContent += attributeValue.e ? evaluate(instance, attributeValue.code, scope) : attributeValue.c;
            }
        }

        if (!values.has(attribute.c)) values.set(attribute.c, valueContent);
    }

    return values;
}

/** Make the element's attributes these; hydrating, remove the others (Viewi's toRemove). */
export function renderAttributeValue(instance, node, element, scope, root, removeOthers) {
    if (scope.disposed) {
        return;
    }
    const values = attributeValues(instance, node, scope);

    if (removeOthers) {
        for (const name of element.getAttributeNames()) {
            if (values.has(name) || name.startsWith('data-fusion-') || (root && RUNTIME_ATTRIBUTES.has(name))) continue;
            element.removeAttribute(name);
        }
    } else {
        /** Those it has no longer (@disabled turned false). */
        for (const attribute of node.a ?? []) {
            if (!values.has(attribute.c) && element.hasAttribute(attribute.c)) element.removeAttribute(attribute.c);
        }
    }

    for (const [attrName, valueContent] of values) {
        if (valueContent === element.getAttribute(attrName)) continue;
        if (attrName.startsWith('xlink:')) element.setAttributeNS(XLINK, attrName, valueContent);
        else element.setAttribute(attrName, valueContent);
    }

    syncFormState(element);
}

function syncFormState(el) {
    if (el.namespaceURI !== HTML) return;
    if (el.localName === 'input') {
        if (el.type === 'checkbox' || el.type === 'radio') {
            const checked = el.hasAttribute('checked');
            if (modelAttribute(el) === null && el.checked !== checked) el.checked = checked;
        } else {
            const value = el.getAttribute('value') ?? '';
            if (modelAttribute(el) === null && el.value !== value && el !== el.ownerDocument.activeElement) el.value = value;
        }
    } else if (el.localName === 'option') {
        el.selected = el.hasAttribute('selected');
    }
}
