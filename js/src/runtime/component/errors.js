/**
 * $errors in the browser: the validation messages of a component's last #[Server] call, with
 * the methods of Laravel's MessageBag that templates use.
 *
 * A bag is never changed: withPaths() makes a new one, and takeErrors() is how a component or a
 * store takes one in.
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

    /** Whether the other bag holds the same messages, in the same order. */
    equals(other) {
        return JSON.stringify([...this.messages]) === JSON.stringify([...other.messages]);
    }

    /**
     * A new bag: the messages at these paths, and at keys below them, replaced by those given
     * (field => list, a Map or an object; none: they are cleared); the others kept.
     */
    withPaths(paths, messages) {
        const under = (field) => paths.some((path) => field === path || field.startsWith(path + '.'));
        const all = new Map([...this.messages].filter(([field]) => !under(field)));
        const given = messages instanceof Map ? [...messages] : Object.entries(messages ?? {});
        for (const [field, list] of given) all.set(toStr(field), entries(list).map(([, m]) => toStr(m)));
        return new ErrorBag(all);
    }
}

/** A component or a store takes the bag as its $errors: whether they changed. */
export function takeErrors(holder, errors) {
    const changed = !holder.errors.equals(errors);
    holder.errors = errors;
    return changed;
}

export const isErrorBag = (v) => v instanceof ErrorBag || isArray(v);
