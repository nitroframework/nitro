/**
 * $errors in the browser: the validation messages of a component's last #[Server] call, with
 * the methods of Laravel's MessageBag that templates use.
 */
import { decode, entries, isArray, toStr } from '../../php.js';

export class ErrorBag {
    /** @param {Map|Array|null} messages field => list of messages (as MessageBag::toArray()) */
    constructor(messages = null) {
        this.messages = new Map();
        for (const [field, list] of entries(messages ?? [])) {
            this.messages.set(toStr(field), entries(list).map(([, m]) => toStr(m)));
        }
    }

    static fromWire(encoded) {
        return new ErrorBag(encoded === undefined ? null : decode(encoded));
    }

    /** has('email'), has('email', 'name'), has('items.*') */
    has(...keys) {
        if (keys.length === 0) return this.any();
        return keys.flat().every((key) => this.matching(toStr(key)).some(([, list]) => list.length > 0));
    }

    first(key = null) {
        const list = key === null ? this.all() : this.matching(toStr(key)).flatMap(([, messages]) => messages);
        return list[0] ?? '';
    }

    get(key) {
        return this.matching(toStr(key)).flatMap(([, messages]) => messages);
    }

    all() {
        return [...this.messages.values()].flat();
    }

    any() {
        return this.count() > 0;
    }

    count() {
        return this.all().length;
    }

    matching(key) {
        if (!key.includes('*')) return this.messages.has(key) ? [[key, this.messages.get(key)]] : [];
        const pattern = new RegExp('^' + key.split('*').map((part) => part.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*') + '$');
        return [...this.messages.entries()].filter(([field]) => pattern.test(field));
    }
}

export const isErrorBag = (v) => v instanceof ErrorBag || isArray(v);
