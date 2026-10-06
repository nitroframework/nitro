/** Errors, and how PHP arrays are represented: a list is a JS Array, any other array a JS Map. Part of php.js. */

export class PhpError extends Error {
    constructor(type, message) {
        super(message);
        this.name = type;
    }
}

export const typeError = (message) => new PhpError('TypeError', message);

// ---------------------------------------------------------------- arrays: representation

export const isList = (v) => Array.isArray(v);
export const isMap = (v) => v instanceof Map;
export const isArray = (v) => Array.isArray(v) || v instanceof Map;

/** PHP array key normalization: "5" -> 5, true -> 1, null -> "", 1.7 -> 1; "05", "1.5" stay strings. */
export function key(k) {
    if (typeof k === 'number') {
        if (!Number.isFinite(k)) throw typeError('Cannot access offset of type float on array');
        return Math.trunc(k);
    }
    if (typeof k === 'string') {
        return /^(0|-?[1-9][0-9]*)$/.test(k) && Number.isSafeInteger(Number(k)) ? Number(k) : k;
    }
    if (k === true) return 1;
    if (k === false) return 0;
    if (k === null || k === undefined) return '';
    throw typeError('Illegal offset type');
}

/** Array (list) or Map to entries. */
export function entries(v) {
    if (Array.isArray(v)) return v.map((x, i) => [i, x]);
    if (v instanceof Map) return [...v.entries()];
    return [];
}

/**
 * A model's attributes ({"$o": ...} on the wire): an array that ->name reads too, as PHP reads an
 * Eloquent model. Writing to one keeps it one.
 */
export class ModelData extends Map {}

/** A Map whose keys are 0..n-1 in order is a list (a model stays a model). */
export function normalize(v) {
    if (!(v instanceof Map) || v instanceof ModelData) return v;
    let i = 0;
    for (const k of v.keys()) {
        if (k !== i++) return v;
    }
    return [...v.values()];
}

export function toMap(v) {
    if (v instanceof ModelData) return new ModelData(v);
    if (v instanceof Map) return new Map(v);
    if (Array.isArray(v)) return new Map(v.map((x, i) => [i, x]));
    return new Map();
}

export function fromEntries(list) {
    return normalize(new Map(list));
}

/** PHP's next free integer key: one past the largest integer key (0 when there is none). */
export function nextIndex(map) {
    let next = 0;
    for (const k of map.keys()) {
        if (typeof k === 'number' && k >= next) next = k + 1;
    }
    return next;
}
