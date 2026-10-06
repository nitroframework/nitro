/** PHP arrays read and written: keys normalized as PHP does, every write a new array (PHP arrays are values); count(). Part of php.js. */
import { ModelData, PhpError, entries, isArray, key, nextIndex, normalize, toMap, typeError } from './core.js';
import { bool, spread, toStr, typeName } from './conversions.js';
import { fromUtf8, utf8 } from './operators.js';
import { scalar } from './functions.js';
import { EnumCase } from './enums.js';

// ---------------------------------------------------------------- arrays: read / write

function arrayOrNull(base) {
    return base === null || base === undefined ? null : base;
}

/** An item of [$a, 'k' => $b] = $base: only an array gives any (a string or a number gives null). */
export function listItem(base, k) {
    return Array.isArray(base) || base instanceof Map ? get(base, k) : null;
}

/**
 * A string offset's position, from its key as PHP reads one: an int, a float (truncated), a bool,
 * null, an integer string (" 1", "01"). A leading-numeric string ("1x") is its number with a
 * warning (not legal: isset() is false). Any other string ("x", "1.0", "") is no offset: null.
 */
function stringOffset(k) {
    if (typeof k === 'number') return { i: Math.trunc(k), legal: true };
    if (k === true) return { i: 1, legal: true };
    if (k === false || k === null || k === undefined) return { i: 0, legal: true };
    if (typeof k === 'string') {
        if (/^[ \t\n\r\v\f]*[+-]?\d+[ \t\n\r\v\f]*$/.test(k)) return { i: Number(k.trim()), legal: true };
        const leading = /^[ \t\n\r\v\f]*([+-]?\d+)(?![.eE\d])/.exec(k);
        if (leading) return { i: Number(leading[1]), legal: false };
    }
    return null;
}

/** $string[$k] = $v: one byte replaced, the string padded with spaces up to it. */
function setStringOffset(base, k, v) {
    if (k === APPEND) throw new PhpError('Error', '[] operator not supported for strings');
    const offset = stringOffset(k);
    if (offset === null) throw typeError(`Cannot access offset of type ${typeName(k)} on string`);
    const bytes = utf8(base);
    let i = offset.i;
    if (i < 0) i += bytes.length;
    /** Before the start: PHP warns and leaves the string. */
    if (i < 0) return base;
    const value = utf8(toStr(v));
    if (value.length === 0) throw new PhpError('Error', 'Cannot assign an empty string to a string offset');
    const out = new Uint8Array(Math.max(bytes.length, i + 1)).fill(0x20);
    out.set(bytes);
    out[i] = value[0];
    return fromUtf8(out);
}

/** $base[$k] (read). Missing keys, and offsets of null, read as null (PHP warns). */
export function get(base, k) {
    base = arrayOrNull(base);
    if (base === null) return null;
    if (Array.isArray(base)) {
        const i = key(k);
        return typeof i === 'number' && i >= 0 && i < base.length ? base[i] : null;
    }
    if (base instanceof Map) {
        const v = base.get(key(k));
        return v === undefined ? null : v;
    }
    if (typeof base === 'string') {
        const offset = stringOffset(k);
        if (offset === null) throw typeError(`Cannot access offset of type ${typeName(k)} on string`);
        const bytes = utf8(base);
        let i = offset.i;
        if (i < 0) i += bytes.length;
        return i >= 0 && i < bytes.length ? fromUtf8(bytes.subarray(i, i + 1)) : '';
    }
    if (typeof base === 'object') {
        const v = base[k];
        return v === undefined ? null : v;
    }
    return null;
}

/**
 * What $base[a][b] += ... and $base[a][b]++ read, after PHP's checks of where they write: a
 * string offset cannot take them, and a scalar (true, a number) holds no keys. null and false
 * become arrays: the value read is null.
 */
export function getForUpdate(base, path, increment = false) {
    let v = base;
    for (let i = 0; i < path.length; i++) {
        if (v === null || v === undefined || v === false) return null;
        if (typeof v === 'string') {
            /** Keys under a string offset: the error a nested write gives. */
            if (i < path.length - 1) return setIn(v, path.slice(i), null);
            if (stringOffset(path[i]) === null) throw typeError(`Cannot access offset of type ${typeName(path[i])} on string`);
            throw new PhpError('Error', increment ? 'Cannot increment/decrement string offsets' : 'Cannot use assign-op operators with string offsets');
        }
        if (v === true || typeof v === 'number') throw new PhpError('Error', 'Cannot use a scalar value as an array');
        v = get(v, path[i]);
    }
    return v;
}

/** match without a matching arm: the value as PHP shows it ('abc', NULL, NAN, of type array). */
export function unhandledMatch(v) {
    const shown = v === null || v === undefined ? 'NULL'
        : typeof v === 'string' ? `'${v.replace(/[\\']/g, '\\$&')}'`
        : isArray(v) ? 'of type array'
        : typeof v === 'object' ? 'of type object'
        : typeof v === 'boolean' ? String(v)
        : toStr(v);
    return new PhpError('UnhandledMatchError', `Unhandled match case ${shown}`);
}

/** The key of $a[$a] = ...: $a once the write made it an array (from null or false). */
export const ownKey = (v) => (v === null || v === undefined || v === false ? [] : v);

/** $base[a][b] ?? ...: the value where isset() finds one, else null (no warning, no error). */
export function peek(base, path) {
    return isset(base, path, true) ? getIn(base, path) : null;
}

/**
 * $base->name: a model's attribute, an object's property, an enum case's ->name and ->value. On an
 * array or a scalar it fails, as it does in a Laravel view; on null it is null ($post?->title).
 */
export function prop(base, name) {
    /** A case: ->name, a backed one's ->value; anything else is null (PHP warns: undefined property). */
    if (base instanceof EnumCase) return name === 'name' || name === 'value' ? (base[name] ?? null) : null;
    if (base instanceof ModelData) return get(base, name);
    if (base === null || base === undefined) return null;
    if (isArray(base) || typeof base !== 'object') {
        throw new PhpError('ErrorException', `Attempt to read property "${name}" on ${typeName(base)}`);
    }
    const v = base[name];
    return v === undefined ? null : v;
}

/** The [] of $base[] = $v, and an array literal's item without a key (null and undefined keys are the key "", as in PHP). */
export const APPEND = Symbol('append');

/** $base[$k] = $v; returns the new array. $k === APPEND appends ($base[] = $v). */
export function set(base, k, v) {
    if (typeof base === 'string') return setStringOffset(base, k, v);
    /** false becomes an array, as null does (PHP deprecates it). */
    base = base === false ? null : arrayOrNull(base);
    if (base === null) base = [];
    if (!isArray(base)) throw new PhpError('Error', 'Cannot use a scalar value as an array');
    if (Array.isArray(base)) {
        if (k === APPEND) return [...base, v];
        const i = key(k);
        if (typeof i === 'number' && i >= 0 && i <= base.length) {
            const copy = base.slice();
            copy[i] = v;
            return copy;
        }
    }
    const map = toMap(base);
    map.set(k === APPEND ? nextIndex(map) : key(k), v);
    return normalize(map);
}

export const push = (base, v) => set(base, APPEND, v);

/** $base[a][b]... = $v, copying only the path. An APPEND segment appends ([] syntax). */
export function setIn(base, path, v) {
    if (path.length === 0) return v;
    const [head, ...rest] = path;
    if (rest.length === 0) return set(base, head, v);
    if (typeof base === 'string') {
        if (head !== APPEND && stringOffset(head) === null) throw typeError(`Cannot access offset of type ${typeName(head)} on string`);
        throw new PhpError('Error', 'Cannot use string offset as an array');
    }
    const child = head === APPEND ? null : get(base, head);
    return set(base, head, setIn(child, rest, v));
}

/** Read along a path (used by compound assignments). */
export function getIn(base, path) {
    let v = base;
    for (const k of path) v = get(v, k);
    return v;
}

/** unset($base[a][b]...), returning the new array. */
export function unsetIn(base, path) {
    if (!isArray(base) || path.length === 0) return base;
    const [head, ...rest] = path;
    const k = key(head);
    if (rest.length > 0) {
        const child = get(base, k);
        return isArray(child) ? set(base, k, unsetIn(child, rest)) : base;
    }
    if (Array.isArray(base)) {
        if (typeof k !== 'number' || k < 0 || k >= base.length) return base;
        if (k === base.length - 1) return base.slice(0, -1);
    }
    const map = toMap(base);
    map.delete(k);
    return normalize(map);
}
/** isset() on a path: every step exists and the final value is not null. ?? (coalescing) also reads a leading-numeric string offset ("1x"), as PHP does. */
/** isset() on a path: every step exists and the final value is not null. */
export function isset(base, path = [], coalescing = false) {
    let v = base;
    for (const k of path) {
        if (v === null || v === undefined) return false;
        if (isArray(v)) {
            v = Array.isArray(v) ? v[key(k)] : v.get(key(k));
        } else if (typeof v === 'string') {
            const offset = stringOffset(k);
            const length = utf8(v).length;
            if (offset === null || (!offset.legal && !coalescing) || offset.i >= length || offset.i < -length) return false;
            v = get(v, offset.i);
        } else if (typeof v === 'object') {
            v = v[k];
        } else {
            return false;
        }
    }
    return v !== null && v !== undefined;
}

/** empty() */
export function empty(base, path = []) {
    return !isset(base, path) || !bool(getIn(base, path));
}

/** foreach source: [key, value] pairs. */
export function iter(v) {
    if (Array.isArray(v)) return v.entries();
    if (v instanceof Map) return v.entries();
    if (v === null || v === undefined || typeof v !== 'object') return [];
    throw typeError(`foreach() argument must be of type array, ${typeName(v)} given`);
}

/** [ ...$a ] and literal building: [[key|APPEND, value, spread?], ...] */
export function arrayLiteral(items) {
    let map = new Map();
    let next = 0;
    let list = true;
    for (const [k, v, spread] of items) {
        if (spread) {
            if (!isArray(v)) throw new PhpError('Error', 'Only arrays can be unpacked');
            for (const [sk, sv] of entries(v)) {
                if (typeof sk === 'number') {
                    map.set(next++, sv);
                } else {
                    map.set(sk, sv);
                    list = false;
                }
            }
            continue;
        }
        if (k === APPEND) {
            map.set(next++, v);
        } else {
            const nk = key(k);
            map.set(nk, v);
            if (typeof nk === 'number' && nk >= next) next = nk + 1;
        }
    }
    return list ? normalize(map) : normalize(map);
}


/**
 * PHP 8's count(): an array's length, or a Countable's count(). Viewi's count() returns 1 for any
 * object (PHP 7); PHP 8 calls Countable::count(), so count($errors) is every message of the
 * MessageBag (TemplateParityTest, todo-list with errors), and other objects are a TypeError.
 */
export function count(v) {
    if (Array.isArray(v)) return v.length;
    if (v instanceof Map) return v.size;
    if (v !== null && typeof v === 'object' && typeof v.count === 'function') return v.count();
    throw typeError(`count(): Argument #1 ($value) must be of type Countable|array, ${typeName(v)} given`);
}
