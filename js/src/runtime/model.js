/**
 * fusion:model="path": two-way binding between a form control and a property (or a key under it).
 *
 *   fusion:model            updates on input
 *   .blur / .change / .lazy updates on change
 *   .debounce.300ms         waits for a pause in typing
 *   .number                 stores a number (as PHP's (float) or (int) would read it)
 *   .trim                   trims the text
 *   .boolean                stores true/false
 */
import * as P from '../php.js';
import { timing } from './events.js';

export function modelAttribute(el) {
    return el.getAttributeNames().find((n) => n === 'fusion:model' || n.startsWith('fusion:model.')) ?? null;
}

export class Model {
    constructor(runtime, document) {
        this.runtime = runtime;
        this.timers = new WeakMap();
        document.addEventListener('input', (e) => this.handle(e, 'input'));
        document.addEventListener('change', (e) => this.handle(e, 'change'));
    }

    handle(event, type) {
        const el = event.target;
        if (!(el instanceof Element)) return;
        const attribute = modelAttribute(el);
        if (!attribute) return;
        const modifiers = attribute.split('.').slice(1);
        const lazy = modifiers.some((m) => m === 'blur' || m === 'change' || m === 'lazy');
        const isToggle = el.type === 'checkbox' || el.type === 'radio' || el.tagName === 'SELECT';
        if (type === 'input' && (lazy || isToggle)) return;
        if (type === 'change' && !lazy && !isToggle) return;

        const component = this.runtime.owner(el);
        if (!component) return;

        const path = el.getAttribute(attribute);
        const apply = () => component.setPath(path, this.read(el, component.getPath(path), modifiers));

        if (modifiers.includes('debounce')) {
            clearTimeout(this.timers.get(el));
            this.timers.set(el, setTimeout(apply, timing(modifiers) ?? 150));
        } else {
            apply();
        }
    }

    read(el, current, modifiers) {
        let value;
        if (el.type === 'checkbox') {
            if (P.isArray(current)) {
                const values = P.entries(current).map(([, v]) => v).filter((v) => P.toStr(v) !== el.value);
                value = el.checked ? [...values, el.value] : values;
            } else {
                value = el.checked;
            }
        } else if (el.tagName === 'SELECT' && el.multiple) {
            value = [...el.selectedOptions].map((o) => o.value);
        } else {
            value = el.value;
        }

        if (typeof value === 'string') {
            if (modifiers.includes('trim')) value = value.trim();
            if (modifiers.includes('number')) value = value === '' ? null : P.isNumericString(value) ? Number(value.trim()) : value;
            if (modifiers.includes('boolean')) value = value === 'true' || value === '1';
        }
        return value;
    }

    /** After a render: controls show the state (the focused one only when it differs). */
    sync(component) {
        for (const el of component.scan('*')) {
            const attribute = modelAttribute(el);
            if (!attribute || this.runtime.owner(el) !== component) continue;
            const value = component.getPath(el.getAttribute(attribute));

            if (el.type === 'checkbox') {
                el.checked = P.isArray(value) ? P.entries(value).some(([, v]) => P.toStr(v) === el.value) : P.bool(value);
            } else if (el.type === 'radio') {
                el.checked = P.toStr(value) === el.value;
            } else if (el.tagName === 'SELECT' && el.multiple) {
                const selected = P.isArray(value) ? P.entries(value).map(([, v]) => P.toStr(v)) : [];
                for (const option of el.options) option.selected = selected.includes(option.value);
            } else {
                const text = value === null ? '' : P.toStr(value);
                if (el.value !== text && !(el === el.ownerDocument.activeElement && P.eq(el.value, value))) el.value = text;
            }
        }
    }
}
