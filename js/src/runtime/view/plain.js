/**
 * PHP values as JavaScript frameworks hold data, and back: a PHP array that is a list is an array,
 * any other PHP array (and a model) is a plain object. Enum cases and uploads stay as they are.
 * A plain object's keys come out in JavaScript's order (integer keys first): a list keeps order.
 */
import * as P from '../../php.js';
import { ModelData } from '../../php/core.js';

const isPlainObject = (value) => value !== null && typeof value === 'object' && [Object.prototype, null].includes(Object.getPrototypeOf(value));

export function toPlain(value) {
    if (Array.isArray(value)) return value.map(toPlain);
    if (value instanceof Map) return Object.fromEntries([...value].map(([key, item]) => [key, toPlain(item)]));
    return value;
}

/**
 * A plain value as PHP has it: an array a list, an object a PHP array, keys as PHP reads them
 * ("5" is 5). An object where `previous` had a model is that model's again.
 */
export function fromPlain(value, previous = null) {
    if (Array.isArray(value)) return value.map((item, i) => fromPlain(item, P.isArray(previous) ? P.get(previous, i) : null));
    if (!isPlainObject(value)) return value;

    const map = previous instanceof ModelData ? new ModelData() : new Map();
    for (const [key, item] of Object.entries(value)) {
        map.set(P.key(key), fromPlain(item, previous instanceof Map ? previous.get(P.key(key)) ?? null : null));
    }
    return P.normalize(map);
}
