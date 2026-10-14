/**
 * Viewi's renderAttributeValue (core/render/renderAttributeValue), for all of an element's
 * attributes at once. Nitro: as the HTML parser does, a repeated attribute keeps its first value
 * (Viewi joins repeated attributes when it compiles); @checked, @disabled, ... are there or not
 * (when); nitro:<event> marks the element for its handler. Attributes set the initial state of
 * inputs; their properties are what the page shows, so those follow.
 */
import { modelAttribute } from '../dom/model.js';
import { evaluate } from './call.js';
import { attributesOf } from './calls.js';
import { decoded } from './text.js';
import { ltrim } from './trim.js';
import { ATTRIBUTES } from '../protocol.js';

/** The attributes the runtime puts on a component's root element: its render keeps them. */
export const RUNTIME_ATTRIBUTES = new Set([ATTRIBUTES.id, ATTRIBUTES.name, ATTRIBUTES.page]);

const HTML = 'http://www.w3.org/1999/xhtml';
const XLINK = 'http://www.w3.org/1999/xlink';

/** The element's attributes: name => value. */
export function attributeValues(instance, node, scope) {
    const values = new Map();

    /** A called Blade component's element: the caller's nitro: attributes, read with the caller's instance. */
    for (const own of attributesOf(instance, node)) {
        const attribute = own.attribute;
        const [reader, readScope] = own.scope === null ? [instance, scope] : [own.instance, own.scope];
        if (attribute.on) values.set(ATTRIBUTES.handler + attribute.on.e, '');

        let valueContent = '';
        /** when: whether it is there (@disabled, a component's attribute); its value, if it has one, still follows. */
        if (attribute.when !== undefined && !evaluate(reader, attribute.when, readScope)) continue;
        if (attribute.h) {
            /**
             * w: where an included view begins (end: ends) in the value; Blade ltrim()s what each
             * renders (trim.js): while one that has rendered nothing yet is open, whitespace goes.
             */
            const trimming = new Set();
            for (const attributeValue of attribute.h) {
                if (attributeValue.w !== undefined) {
                    attributeValue.end ? trimming.delete(attributeValue.w) : trimming.add(attributeValue.w);
                    continue;
                }
                let part;
                if (!attributeValue.e) part = attributeValue.c;
                else if (attributeValue.d) part = decoded(reader.document, evaluate(reader, attributeValue.code, readScope), true);
                else part = evaluate(reader, attributeValue.code, readScope);
                if (trimming.size > 0) {
                    /** No further than its literal whitespace (ls): a character reference's stays, as in Blade's string. */
                    part = ltrim(part, attributeValue.ls ?? Infinity);
                    if (part !== '') trimming.clear();
                }
                valueContent += part;
            }
        }

        if (!values.has(attribute.c)) values.set(attribute.c, valueContent);
    }

    return values;
}

/**
 * Make the element's attributes the view's, changing only what the view changed since its last
 * render of it (ARCHITECTURE.md, "What Nitro shares"): an attribute a script added, or one whose
 * value in the view is as before, is left as it is; class by its tokens, so a script's classes stay
 * beside the view's. The element's first render here (made here, or the server's, hydrated) sets
 * what the view has and removes nothing.
 */
export function renderAttributeValue(instance, node, element, scope) {
    if (scope.disposed) {
        return;
    }
    const values = attributeValues(instance, node, scope);
    const before = element._attributes;

    /** Those it has no longer (@disabled turned false). */
    for (const [name, value] of before ?? []) {
        if (values.has(name)) continue;
        if (name === 'class') renderClasses(element, value, '');
        else element.removeAttribute(name);
    }

    for (const [attrName, valueContent] of values) {
        const was = before?.get(attrName);
        if (was === valueContent) continue;
        if (attrName === 'class' && element.hasAttribute('class')) renderClasses(element, was ?? null, valueContent);
        else if (valueContent === element.getAttribute(attrName)) continue;
        else if (attrName.startsWith('xlink:')) element.setAttributeNS(XLINK, attrName, valueContent);
        else element.setAttribute(attrName, valueContent);
    }
    element._attributes = values;

    syncFormState(element);
}

/**
 * The view's classes as it renders them (was: its classes before, null for none), then any others
 * the element has, a script's: the view's string exactly when there are none.
 */
function renderClasses(element, was, now) {
    const own = new Set([...(was ?? '').split(/\s+/), ...now.split(/\s+/)]);
    const theirs = [...element.classList].filter((token) => !own.has(token));
    const value = theirs.length === 0 ? now : [now.trim(), ...theirs].filter(Boolean).join(' ');
    if (value !== element.getAttribute('class')) element.setAttribute('class', value);
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
