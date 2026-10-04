/**
 * PHP semantics for code compiled from PHP: values, operators and the functions a component may
 * call. Everything the compiler emits goes through here, so PHP's rules hold in the browser.
 *
 * Values:
 *   null, bool, number, string    as in JS (PHP int and float are both a JS number)
 *   a PHP array that is a list    a JS Array
 *   any other PHP array           a JS Map (insertion order, int or string keys, as in PHP)
 *
 * The invariant "Array if and only if array_is_list()" is kept by every function here, so a value
 * encodes to the same JSON in PHP and in JS. Arrays are values in PHP; nothing here mutates an
 * array in place: every write returns a new array (copying only the path it changes).
 */

export class PhpError extends Error {
    constructor(type, message) {
        super(message);
        this.name = type;
    }
}

const typeError = (message) => new PhpError('TypeError', message);

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

/** A Map whose keys are 0..n-1 in order is a list. */
export function normalize(v) {
    if (!(v instanceof Map)) return v;
    let i = 0;
    for (const k of v.keys()) {
        if (k !== i++) return v;
    }
    return [...v.values()];
}

function toMap(v) {
    if (v instanceof Map) return new Map(v);
    if (Array.isArray(v)) return new Map(v.map((x, i) => [i, x]));
    return new Map();
}

function fromEntries(list) {
    return normalize(new Map(list));
}

/** PHP's next free integer key: one past the largest integer key (0 when there is none). */
function nextIndex(map) {
    let next = 0;
    for (const k of map.keys()) {
        if (typeof k === 'number' && k >= next) next = k + 1;
    }
    return next;
}

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

/** $base->name on data (models arrive as arrays). */
export function prop(base, name) {
    if (base instanceof Map || Array.isArray(base)) return get(base, name);
    if (base !== null && typeof base === 'object') {
        const v = base[name];
        return v === undefined ? null : v;
    }
    return null;
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

// ---------------------------------------------------------------- conversions

export function typeName(v) {
    if (v === null || v === undefined) return 'null';
    if (v instanceof Upload) return 'Nitro\\Fusion\\Upload';
    if (typeof v === 'boolean') return 'bool';
    if (typeof v === 'number') return Number.isInteger(v) ? 'int' : 'float';
    if (typeof v === 'string') return 'string';
    if (isArray(v)) return 'array';
    return 'object';
}

/** PHP truthiness. */
export function bool(v) {
    if (v === null || v === undefined || v === false) return false;
    if (typeof v === 'number') return v !== 0;
    if (typeof v === 'string') return v !== '' && v !== '0';
    if (Array.isArray(v)) return v.length > 0;
    if (v instanceof Map) return v.size > 0;
    return true;
}

/** Float to string as PHP prints it (precision = 14, %.14G, "1.0E+25"). */
export function floatToString(v) {
    if (Number.isNaN(v)) return 'NAN';
    if (v === Infinity) return 'INF';
    if (v === -Infinity) return '-INF';
    if (v === 0) return Object.is(v, -0) ? '-0' : '0';
    const [mantissa, exp] = v.toExponential(13).split('e');
    const e = Number(exp);
    let digits = mantissa.replace('-', '').replace('.', '').replace(/0+$/, '');
    const sign = v < 0 ? '-' : '';
    if (e < -4 || e >= 14) {
        const m = digits.length > 1 ? digits[0] + '.' + digits.slice(1) : digits + '.0';
        return `${sign}${m}E${e < 0 ? '-' : '+'}${Math.abs(e)}`;
    }
    if (e < 0) return `${sign}0.${'0'.repeat(-e - 1)}${digits}`;
    if (digits.length <= e + 1) return sign + digits + '0'.repeat(e + 1 - digits.length);
    return `${sign}${digits.slice(0, e + 1)}.${digits.slice(e + 1)}`;
}

/** (string) $v, and string context (echo, concatenation, interpolation). */
export function toStr(v) {
    if (v === null || v === undefined || v === false) return '';
    if (v === true) return '1';
    if (typeof v === 'string') return v;
    if (typeof v === 'number') {
        /**
         * JS has one number type: an integral value prints as a PHP int. A PHP float such as
         * 1.0E+14 (integral, 1e14 or more) is the one case that prints differently.
         */
        return Number.isSafeInteger(v) ? String(v) : floatToString(v);
    }
    if (isArray(v)) return 'Array';
    if (typeof v === 'object' && typeof v.toString === 'function' && v.toString !== Object.prototype.toString) return v.toString();
    throw new PhpError('Error', `Object of class ${v?.constructor?.name ?? 'object'} could not be converted to string`);
}

const NUMERIC = /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/;
const LEADING_NUMERIC = /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/;

export const isNumericString = (s) => typeof s === 'string' && NUMERIC.test(s);

/** A number from a numeric string, or null. */
function parseNumeric(s, leading = false) {
    const m = (leading ? LEADING_NUMERIC : NUMERIC).exec(s);
    return m ? Number(m[0].trim()) : null;
}

/** Operand of arithmetic: PHP 8 rules (numeric and leading-numeric strings, TypeError otherwise). */
export function toNum(v, op = '+', other = 'int') {
    if (typeof v === 'number') return v;
    if (v === null || v === undefined || v === false) return 0;
    if (v === true) return 1;
    if (typeof v === 'string') {
        const n = parseNumeric(v, true);
        if (n === null) throw typeError(`Unsupported operand types: string ${op} ${other}`);
        return n;
    }
    throw typeError(`Unsupported operand types: ${typeName(v)} ${op} ${other}`);
}

/** (int) */
export function toInt(v) {
    if (typeof v === 'number') return Number.isFinite(v) ? Math.trunc(v) : 0;
    if (typeof v === 'string') {
        const n = parseNumeric(v, true);
        return n === null ? 0 : Number.isFinite(n) ? Math.trunc(n) : 0;
    }
    if (v === true) return 1;
    if (isArray(v)) return count(v) > 0 ? 1 : 0;
    return 0;
}

/** (float) */
export function toFloat(v) {
    if (typeof v === 'number') return v;
    if (typeof v === 'string') return parseNumeric(v, true) ?? 0;
    if (v === true) return 1;
    if (isArray(v)) return count(v) > 0 ? 1 : 0;
    return 0;
}

/** (array) */
export function toArr(v) {
    if (isArray(v)) return v;
    if (v === null || v === undefined) return [];
    return [v];
}

/**
 * A typed parameter in PHP's coercive mode: int|float take numbers, numeric strings and bools;
 * string takes scalars; bool takes scalars; anything else (and null, unless nullable) is a
 * TypeError. Mirrors what PHP does when a template or handler passes a value to a method.
 */
export function param(v, type, nullable, label) {
    if (v === null || v === undefined) {
        if (nullable) return null;
        throw typeError(`${label} must be of type ${type}, null given`);
    }
    const fail = () => { throw typeError(`${label} must be of type ${type}, ${typeName(v)} given`); };
    switch (type) {
        case 'int':
        case 'float':
            if (typeof v === 'number') return type === 'int' ? Math.trunc(v) : v;
            if (typeof v === 'boolean') return v ? 1 : 0;
            if (typeof v === 'string' && isNumericString(v)) {
                const n = Number(v.trim());
                return type === 'int' ? Math.trunc(n) : n;
            }
            return fail();
        case 'string':
            if (typeof v === 'string') return v;
            if (typeof v === 'number' || typeof v === 'boolean') return toStr(v);
            return fail();
        case 'bool':
            if (typeof v === 'boolean') return v;
            if (typeof v === 'number' || typeof v === 'string') return bool(v);
            return fail();
        case 'array':
            return isArray(v) ? v : fail();
        default:
            return v;
    }
}

/** f(...$args): a list spreads as positional arguments (string keys would be named arguments). */
export function spread(v) {
    if (!isArray(v)) throw new PhpError('Error', 'Only arrays can be unpacked');
    const list = entries(v);
    if (list.some(([k]) => typeof k === 'string')) throw new PhpError('Error', 'Named arguments are not supported in the browser');
    return list.map(([, x]) => x);
}

// ---------------------------------------------------------------- operators

export function add(a, b) {
    if (isArray(a) && isArray(b)) {
        const map = toMap(a);
        for (const [k, v] of entries(b)) if (!map.has(k)) map.set(k, v);
        return normalize(map);
    }
    if (isArray(a) || isArray(b)) throw typeError(`Unsupported operand types: ${typeName(a)} + ${typeName(b)}`);
    return toNum(a, '+', typeName(b)) + toNum(b, '+', typeName(a));
}

export const sub = (a, b) => toNum(a, '-', typeName(b)) - toNum(b, '-', typeName(a));
export const mul = (a, b) => toNum(a, '*', typeName(b)) * toNum(b, '*', typeName(a));

/** The operands' types are checked before the divisor is (PHP's order: TypeError, then DivisionByZeroError). */
export function div(a, b) {
    const n = toNum(a, '/', typeName(b));
    const d = toNum(b, '/', typeName(a));
    if (d === 0) throw new PhpError('DivisionByZeroError', 'Division by zero');
    return n / d;
}

export function mod(a, b) {
    const n = toInt(toNum(a, '%', typeName(b)));
    const d = toInt(toNum(b, '%', typeName(a)));
    if (d === 0) throw new PhpError('DivisionByZeroError', 'Modulo by zero');
    const r = n % d;
    return r === 0 ? 0 : r;
}

export const pow = (a, b) => toNum(a, '**') ** toNum(b, '**');
export const neg = (a) => -toNum(a, '*');
export const concat = (...parts) => parts.map(toStr).join('');

export function inc(v) {
    if (v === null || v === undefined) return 1;
    if (typeof v === 'boolean') return v;
    if (typeof v === 'string' && !isNumericString(v)) return strInc(v);
    return toNum(v) + 1;
}

export function dec(v) {
    if (v === null || v === undefined) return null;
    if (typeof v === 'boolean') return v;
    if (v === '') return -1;
    if (typeof v === 'string' && !isNumericString(v)) return v;
    return toNum(v) - 1;
}

/** PHP's "z"++ -> "aa". */
function strInc(s) {
    if (s === '') return '1';
    const chars = s.split('');
    let i = chars.length - 1;
    while (i >= 0) {
        const c = chars[i];
        if (c === 'z') { chars[i] = 'a'; i--; continue; }
        if (c === 'Z') { chars[i] = 'A'; i--; continue; }
        if (c === '9') { chars[i] = '0'; i--; continue; }
        if (/[a-yA-Y0-8]/.test(c)) { chars[i] = String.fromCharCode(c.charCodeAt(0) + 1); return chars.join(''); }
        return chars.join('');
    }
    const first = s[0];
    return (first === 'z' || first === 'Z' ? (first === 'z' ? 'a' : 'A') : '1') + chars.join('');
}

/** PHP 8 comparison, <=>: -1, 0 or 1 (arrays that cannot be compared answer 1 in both directions). */
export function cmp(a, b) {
    if (a === undefined) a = null;
    if (b === undefined) b = null;
    if (isArray(a) && isArray(b)) {
        const ca = count(a);
        const cb = count(b);
        if (ca !== cb) return ca < cb ? -1 : 1;
        for (const [k, v] of entries(a)) {
            const other = Array.isArray(b) ? (typeof k === 'number' && k < b.length ? b[k] : undefined) : b.get(k);
            if (other === undefined) return 1;
            const c = cmp(v, other);
            if (c !== 0) return c;
        }
        return 0;
    }
    if (typeof a === 'string' && typeof b === 'string') {
        if (isNumericString(a) && isNumericString(b)) return sign(Number(a.trim()) - Number(b.trim()));
        return a < b ? -1 : a > b ? 1 : 0;
    }
    if (a === null && typeof b === 'string') return b === '' ? 0 : -1;
    if (b === null && typeof a === 'string') return a === '' ? 0 : 1;
    if (typeof a === 'boolean' || typeof b === 'boolean' || a === null || b === null) {
        return sign(Number(bool(a)) - Number(bool(b)));
    }
    if (isArray(a)) return 1;
    if (isArray(b)) return -1;
    if (typeof a === 'number' && typeof b === 'number') return sign(a - b);
    const [num, str, flip] = typeof a === 'number' ? [a, b, 1] : [b, a, -1];
    if (typeof str === 'string') {
        if (isNumericString(str)) return flip * sign(num - Number(str.trim()));
        const s = toStr(num);
        return flip * (s < str ? -1 : s > str ? 1 : 0);
    }
    return 0;
}

function sign(n) {
    return n < 0 ? -1 : n > 0 ? 1 : 0;
}

export const eq = (a, b) => cmp(a, b) === 0;
export const neq = (a, b) => !eq(a, b);
export const lt = (a, b) => cmp(a, b) < 0;
export const gt = (a, b) => cmp(b, a) < 0;
export const lte = (a, b) => cmp(a, b) <= 0;
export const gte = (a, b) => cmp(b, a) <= 0;

/** === */
export function identical(a, b) {
    if (a === undefined) a = null;
    if (b === undefined) b = null;
    if (isArray(a) || isArray(b)) {
        if (!isArray(a) || !isArray(b) || count(a) !== count(b)) return false;
        const ea = entries(a);
        const eb = entries(b);
        return ea.every(([k, v], i) => eb[i][0] === k && identical(v, eb[i][1]));
    }
    return a === b;
}

export const notIdentical = (a, b) => !identical(a, b);

/** ?? on a value that may be undefined (a missing key). */
export const coalesce = (v, fallback) => (v === null || v === undefined ? fallback() : v);

// ---------------------------------------------------------------- strings: bytes

const encoder = new TextEncoder();
const decoder = new TextDecoder('utf-8');

export const utf8 = (s) => encoder.encode(s);
export const fromUtf8 = (bytes) => decoder.decode(bytes);

const ASCII_LOWER = (s) => s.replace(/[A-Z]/g, (c) => c.toLowerCase());
const ASCII_UPPER = (s) => s.replace(/[a-z]/g, (c) => c.toUpperCase());

/** htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $double), as Laravel's e(). */
export function e(v, double = true) {
    if (v === null || v === undefined) return '';
    if (isArray(v)) throw typeError('htmlspecialchars(): Argument #1 ($string) must be of type string, array given');
    const s = toStr(v);
    return (double ? s.replace(/&/g, '&amp;') : s.replace(/&(?!(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);)/gi, '&amp;'))
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ---------------------------------------------------------------- JSON (PHP json_encode defaults)

export function jsonEncode(v, flags = 0) {
    const HEX_TAG = 1;
    const HEX_AMP = 2;
    const HEX_APOS = 4;
    const HEX_QUOT = 8;
    const PRETTY = 128;
    const UNESCAPED_SLASHES = 64;
    const UNESCAPED_UNICODE = 256;
    const str = (s) => {
        let out = JSON.stringify(s);
        if (flags & HEX_QUOT) out = '"' + out.slice(1, -1).replace(/\\"/g, '\\u0022') + '"';
        if (flags & HEX_TAG) out = out.replace(/</g, '\\u003C').replace(/>/g, '\\u003E');
        if (flags & HEX_AMP) out = out.replace(/&/g, '\\u0026');
        if (flags & HEX_APOS) out = out.replace(/'/g, '\\u0027');
        if (!(flags & UNESCAPED_SLASHES)) out = out.replace(/\//g, '\\/');
        if (!(flags & UNESCAPED_UNICODE)) {
            out = out.replace(/[\u007f-￿]/g, (c) => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0'));
        }
        return out;
    };
    const indent = (level) => (flags & PRETTY ? '\n' + '    '.repeat(level) : '');
    const walk = (x, level) => {
        if (x === null || x === undefined) return 'null';
        if (typeof x === 'boolean') return x ? 'true' : 'false';
        if (typeof x === 'number') {
            if (!Number.isFinite(x)) throw new PhpError('JsonException', 'Inf and NaN cannot be JSON encoded');
            return Number.isInteger(x) && Math.abs(x) < 1e15 ? String(x) : String(x).replace('e+', 'e+');
        }
        if (typeof x === 'string') return str(x);
        const sep = flags & PRETTY ? ': ' : ':';
        if (Array.isArray(x)) {
            if (x.length === 0) return '[]';
            return '[' + x.map((y) => indent(level + 1) + walk(y, level + 1)).join(',') + indent(level) + ']';
        }
        if (x instanceof Map) {
            if (x.size === 0) return flags & 16 ? '{}' : '[]';
            return '{' + [...x.entries()].map(([k, y]) => indent(level + 1) + str(String(k)) + sep + walk(y, level + 1)).join(',') + indent(level) + '}';
        }
        return walk(new Map(Object.entries(x)), level);
    };
    return walk(v, 0);
}

/** json_decode($s, true) */
export function jsonDecode(s) {
    let parsed;
    try {
        parsed = JSON.parse(s);
    } catch {
        return null;
    }
    const walk = (x) => {
        if (Array.isArray(x)) return x.map(walk);
        if (x !== null && typeof x === 'object') {
            return normalize(new Map(Object.keys(x).map((k) => [key(k), walk(x[k])])));
        }
        return x;
    };
    return walk(parsed);
}

// ---------------------------------------------------------------- rounding and number formatting

/** round() as PHP 8.4+: the value is rounded as the decimal it prints as, halves away from zero. */
export function round(v, precision = 0) {
    v = toFloat(v);
    if (!Number.isFinite(v)) return v;
    precision = toInt(precision);
    const [digits, exp] = decimal(Math.abs(v));
    /** v = 0.<digits> x 10^(exp + 1); keep the digits before the rounding position. */
    const kept = exp + 1 + precision;
    if (kept < 0) return v < 0 ? -0 : 0;
    if (kept >= digits.length) return v;
    let head = digits.slice(0, kept) || '0';
    if (digits.charCodeAt(kept) >= 53) head = incrementDigits(head); // next digit >= '5'
    const result = Number(`${head}e${exp + 1 - kept}`);
    return v < 0 ? -result : result;
}

/** Shortest round-trip decimal: [significant digits, exponent of the first digit]. */
function decimal(v) {
    if (v === 0) return ['0', 0];
    const [m, e] = v.toExponential().split('e');
    return [m.replace('.', ''), Number(e)];
}

function incrementDigits(s) {
    const d = s.split('').map(Number);
    let i = d.length - 1;
    while (i >= 0) {
        if (d[i] < 9) { d[i]++; return d.join(''); }
        d[i] = 0;
        i--;
    }
    return '1' + d.join('');
}

/** round() to a fixed-point string with exactly $decimals digits. */
function toFixedPhp(v, decimals) {
    const r = round(v, decimals);
    const [digits, exp] = decimal(Math.abs(r));
    let intPart;
    let frac;
    if (r === 0) {
        intPart = '0';
        frac = '';
    } else if (exp >= 0) {
        const all = digits.padEnd(exp + 1, '0');
        intPart = all.slice(0, exp + 1);
        frac = all.slice(exp + 1);
    } else {
        intPart = '0';
        frac = '0'.repeat(-exp - 1) + digits;
    }
    frac = frac.padEnd(decimals, '0').slice(0, decimals);
    return [r < 0 ? '-' : '', intPart, frac];
}

export function number_format(num, decimals = 0, decPoint = '.', thousands = ',') {
    decimals = Math.max(0, toInt(decimals));
    let [signPart, intPart, frac] = toFixedPhp(toFloat(num), decimals);
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, toStr(thousands));
    const out = intPart + (decimals > 0 ? toStr(decPoint) + frac : '');
    return (signPart && /[1-9]/.test(intPart + frac) ? '-' : '') + out;
}

// ---------------------------------------------------------------- sprintf

export function sprintf(format, ...args) {
    format = toStr(format);
    let argIndex = 0;
    return format.replace(/%(?:(\d+)\$)?([-+ 0]|'.)*(\d+)?(?:\.(\d+))?([bcdeEfFgGosuxX%])/g, (match, argnum, _f, width, precision, conv) => {
        if (conv === '%') return '%';
        const flagsPart = match.slice(1 + (argnum ? argnum.length + 1 : 0)).match(/^([-+ 0]|'.)*/)[0];
        let pad = ' ';
        let left = false;
        let plus = false;
        for (let i = 0; i < flagsPart.length; i++) {
            const f = flagsPart[i];
            if (f === '-') left = true;
            else if (f === '+') plus = true;
            else if (f === '0') pad = '0';
            else if (f === ' ') pad = ' ';
            else if (f === "'") { pad = flagsPart[i + 1]; i++; }
        }
        const idx = argnum ? Number(argnum) - 1 : argIndex++;
        if (idx >= args.length) throw new PhpError('ArgumentCountError', `${args.length + 1} arguments are required, ${idx + 2} given`);
        const arg = args[idx];
        let s;
        switch (conv) {
            case 'd': {
                const n = toInt(arg);
                s = (n < 0 ? '-' : plus ? '+' : '') + String(Math.abs(n));
                break;
            }
            case 'u': {
                const n = toInt(arg);
                s = n < 0 ? (BigInt(n) + 18446744073709551616n).toString() : String(n);
                break;
            }
            case 'f':
            case 'F': {
                /** printf rounds the exact binary value (1.005 -> "1.00"), unlike round(). */
                const p = precision === undefined ? 6 : Number(precision);
                const f = toFloat(arg);
                s = Math.abs(f) < 1e21 ? f.toFixed(p) : toFixedPhp(f, p).join('.');
                if (plus && f >= 0) s = '+' + s;
                break;
            }
            case 'e':
            case 'E': {
                const p = precision === undefined ? 6 : Number(precision);
                let [m, x] = toFloat(arg).toExponential(p).split('e');
                s = (plus && !m.startsWith('-') ? '+' : '') + m + (conv === 'E' ? 'E' : 'e') + (x.startsWith('-') ? '-' : '+') + x.replace(/^[+-]/, '');
                break;
            }
            case 's': {
                s = toStr(arg);
                if (precision !== undefined) s = s.slice(0, Number(precision));
                break;
            }
            case 'x': s = (toInt(arg) >>> 0).toString(16); break;
            case 'X': s = (toInt(arg) >>> 0).toString(16).toUpperCase(); break;
            case 'o': s = (toInt(arg) >>> 0).toString(8); break;
            case 'b': s = (toInt(arg) >>> 0).toString(2); break;
            case 'c': s = String.fromCharCode(toInt(arg)); break;
            default: s = toStr(arg);
        }
        if (width !== undefined && s.length < Number(width)) {
            const n = Number(width) - s.length;
            if (left) s = s + (pad === '0' ? ' ' : pad).repeat(n);
            else if (pad === '0' && /^[+-]/.test(s) && 'dfFeE'.includes(conv)) s = s[0] + '0'.repeat(n) + s.slice(1);
            else s = pad.repeat(n) + s;
        }
        return s;
    });
}

// ---------------------------------------------------------------- the functions components may call

function need(fn, v, type, n = 1) {
    if (type === 'array' && !isArray(v)) {
        throw typeError(`${fn}(): Argument #${n} must be of type array, ${typeName(v)} given`);
    }
    return v;
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

/** A PHP callable: a closure, or the name of a function available in the browser ('intval'). */
export function callable(cb) {
    if (typeof cb === 'function') return cb;
    if (typeof cb === 'string' && typeof fns[cb.toLowerCase()] === 'function') return fns[cb.toLowerCase()];
    throw typeError(`Argument must be a valid callback, ${typeof cb === 'string' ? `function "${cb}" not found or invalid function name` : 'no array or string given'}`);
}

export const fns = {
    // strings
    strlen: (s) => utf8(toStr(s)).length,
    mb_strlen: (s) => [...toStr(s)].length,
    strtolower: (s) => ASCII_LOWER(toStr(s)),
    strtoupper: (s) => ASCII_UPPER(toStr(s)),
    mb_strtolower: (s) => toStr(s).toLowerCase(),
    mb_strtoupper: (s) => toStr(s).toUpperCase(),
    ucfirst: (s) => { s = toStr(s); return ASCII_UPPER(s.slice(0, 1)) + s.slice(1); },
    lcfirst: (s) => { s = toStr(s); return ASCII_LOWER(s.slice(0, 1)) + s.slice(1); },
    ucwords: (s, delimiters = ' \t\r\n\f\v') => {
        s = toStr(s);
        let out = '';
        let upper = true;
        for (const c of s) {
            out += upper ? ASCII_UPPER(c) : c;
            upper = toStr(delimiters).includes(c);
        }
        return out;
    },
    str_contains: (h, n) => toStr(h).includes(toStr(n)),
    str_starts_with: (h, n) => toStr(h).startsWith(toStr(n)),
    str_ends_with: (h, n) => toStr(h).endsWith(toStr(n)),
    trim: (s, chars) => trimWith(toStr(s), chars, true, true),
    ltrim: (s, chars) => trimWith(toStr(s), chars, true, false),
    rtrim: (s, chars) => trimWith(toStr(s), chars, false, true),
    str_repeat: (s, n) => {
        n = toInt(n);
        if (n < 0) throw new PhpError('ValueError', 'str_repeat(): Argument #2 ($times) must be greater than or equal to 0');
        return toStr(s).repeat(n);
    },
    str_pad: (s, length, padString = ' ', type = 1) => {
        /** Lengths are in bytes, as in PHP. */
        const bytes = utf8(toStr(s));
        const pad = utf8(toStr(padString));
        const total = toInt(length) - bytes.length;
        if (total <= 0) return toStr(s);
        if (pad.length === 0) throw new PhpError('ValueError', 'str_pad(): Argument #3 ($pad_string) must not be empty');
        const make = (n) => {
            const out = new Uint8Array(n);
            for (let i = 0; i < n; i++) out[i] = pad[i % pad.length];
            return out;
        };
        const join = (...parts) => fromUtf8(Uint8Array.from(parts.flatMap((p) => [...p])));
        if (type === 0) return join(make(total), bytes); // STR_PAD_LEFT
        if (type === 2) return join(make(Math.floor(total / 2)), bytes, make(Math.ceil(total / 2))); // STR_PAD_BOTH
        return join(bytes, make(total));
    },
    str_replace: (search, replace, subject) => strReplace(search, replace, subject, false),
    str_ireplace: (search, replace, subject) => strReplace(search, replace, subject, true),
    substr: (s, start, length = null) => {
        const bytes = utf8(toStr(s));
        const n = bytes.length;
        let from = toInt(start);
        if (from < 0) from = Math.max(0, n + from);
        if (from > n) return '';
        let to = n;
        if (length !== null && length !== undefined) {
            const l = toInt(length);
            to = l < 0 ? Math.max(from, n + l) : Math.min(n, from + l);
        }
        return fromUtf8(bytes.subarray(from, to));
    },
    mb_substr: (s, start, length = null) => {
        const chars = [...toStr(s)];
        let from = toInt(start);
        if (from < 0) from = Math.max(0, chars.length + from);
        let to = chars.length;
        if (length !== null && length !== undefined) {
            const l = toInt(length);
            to = l < 0 ? Math.max(from, chars.length + l) : Math.min(chars.length, from + l);
        }
        return chars.slice(from, to).join('');
    },
    strpos: (h, n, offset = 0) => bytePos(toStr(h), toStr(n), toInt(offset), false),
    stripos: (h, n, offset = 0) => bytePos(ASCII_LOWER(toStr(h)), ASCII_LOWER(toStr(n)), toInt(offset), false),
    /** PHP's offsets: from there on, or (negative) matches starting that far from the end at most. */
    strrpos: (h, n, offset = 0) => {
        const hb = utf8(toStr(h));
        const nb = utf8(toStr(n));
        offset = toInt(offset);
        let from = 0;
        let end = hb.length;
        if (offset >= 0) {
            if (offset > hb.length) throw new PhpError('ValueError', 'strrpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)');
            from = offset;
        } else {
            if (-offset > hb.length) throw new PhpError('ValueError', 'strrpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)');
            if (-offset >= nb.length) end = hb.length + offset + nb.length;
        }
        for (let i = end - nb.length; i >= from; i--) {
            if (nb.every((b, j) => hb[i + j] === b)) return i;
        }
        return false;
    },
    substr_count: (h, n) => {
        h = toStr(h);
        n = toStr(n);
        if (n === '') throw new PhpError('ValueError', 'substr_count(): Argument #2 ($needle) cannot be empty');
        return h.split(n).length - 1;
    },
    strrev: (s) => fromUtf8(utf8(toStr(s)).reverse()),
    str_split: (s, length = 1) => {
        const bytes = utf8(toStr(s));
        const out = [];
        const l = toInt(length);
        for (let i = 0; i < bytes.length; i += l) out.push(fromUtf8(bytes.subarray(i, i + l)));
        return out;
    },
    nl2br: (s) => toStr(s).replace(/(\r\n|\n\r|\n|\r)/g, '<br />$1'),
    implode: (sep, arr) => {
        if (isArray(sep)) [sep, arr] = [arr ?? '', sep];
        return entries(need('implode', arr, 'array', 2)).map(([, v]) => toStr(v)).join(toStr(sep));
    },
    explode: (sep, s, limit = Number.MAX_SAFE_INTEGER) => {
        sep = toStr(sep);
        s = toStr(s);
        if (sep === '') throw new PhpError('ValueError', 'explode(): Argument #1 ($separator) cannot be empty');
        const parts = s.split(sep);
        limit = toInt(limit);
        if (limit > 0) return parts.length > limit ? [...parts.slice(0, limit - 1), parts.slice(limit - 1).join(sep)] : parts;
        if (limit < 0) return parts.slice(0, limit);
        return [parts.join(sep)];
    },
    sprintf,
    number_format,
    htmlspecialchars: (s) => e(s),
    e: (s) => e(s),
    strval: (v) => toStr(v),

    // numbers
    abs: (n) => Math.abs(toNum(n)),
    round,
    floor: (n) => Math.floor(toNum(n)),
    ceil: (n) => Math.ceil(toNum(n)),
    intdiv: (a, b) => {
        b = toInt(b);
        if (b === 0) throw new PhpError('DivisionByZeroError', 'Division by zero');
        return Math.trunc(toInt(a) / b);
    },
    fmod: (a, b) => toFloat(a) % toFloat(b),
    sqrt: (n) => Math.sqrt(toNum(n)),
    pow: (a, b) => pow(a, b),
    max: (...args) => extreme(args, 1, 'max'),
    min: (...args) => extreme(args, -1, 'min'),
    intval: (v) => toInt(v),
    floatval: (v) => toFloat(v),
    boolval: (v) => bool(v),
    is_numeric: (v) => typeof v === 'number' || isNumericString(v),
    is_string: (v) => typeof v === 'string',
    is_array: (v) => isArray(v),
    is_bool: (v) => typeof v === 'boolean',
    is_null: (v) => v === null || v === undefined,
    random_int: (min, max) => {
        min = toInt(min);
        max = toInt(max);
        return min + Math.floor(Math.random() * (max - min + 1));
    },

    // arrays
    count,
    array_is_list: (a) => Array.isArray(need('array_is_list', a, 'array')),
    array_keys: (a) => entries(need('array_keys', a, 'array')).map(([k]) => k),
    array_values: (a) => entries(need('array_values', a, 'array')).map(([, v]) => v),
    array_key_exists: (k, a) => (Array.isArray(a) ? typeof key(k) === 'number' && key(k) >= 0 && key(k) < a.length : isMap(a) && a.has(key(k))),
    array_key_first: (a) => entries(need('array_key_first', a, 'array'))[0]?.[0] ?? null,
    array_key_last: (a) => entries(need('array_key_last', a, 'array')).at(-1)?.[0] ?? null,
    in_array: (needle, a, strict = false) => entries(need('in_array', a, 'array', 2)).some(([, v]) => (strict ? identical(v, needle) : eq(v, needle))),
    array_search: (needle, a, strict = false) => {
        const hit = entries(need('array_search', a, 'array', 2)).find(([, v]) => (strict ? identical(v, needle) : eq(v, needle)));
        return hit ? hit[0] : false;
    },
    array_merge: (...arrays) => {
        const map = new Map();
        let next = 0;
        arrays.forEach((a, i) => {
            for (const [k, v] of entries(need('array_merge', a, 'array', i + 1))) {
                typeof k === 'number' ? map.set(next++, v) : map.set(k, v);
            }
        });
        return normalize(map);
    },
    array_filter: (a, cb = null, mode = 0) => {
        need('array_filter', a, 'array');
        if (cb !== null) cb = callable(cb);
        const test = cb === null
            ? ([, v]) => bool(v)
            : mode === 2 ? ([k]) => bool(cb(k)) // ARRAY_FILTER_USE_KEY
            : mode === 1 ? ([k, v]) => bool(cb(v, k)) // ARRAY_FILTER_USE_BOTH
            : ([, v]) => bool(cb(v));
        return fromEntries(entries(a).filter(test));
    },
    array_map: (cb, a, ...more) => {
        need('array_map', a, 'array', 2);
        if (more.length === 0) {
            if (cb === null) return a;
            return fromEntries(entries(a).map(([k, v]) => [k, callable(cb)(v)]));
        }
        const lists = [a, ...more].map((x) => entries(x).map(([, v]) => v));
        const n = Math.max(...lists.map((l) => l.length));
        return Array.from({ length: n }, (_, i) => {
            const args = lists.map((l) => (i < l.length ? l[i] : null));
            return cb === null ? args : callable(cb)(...args);
        });
    },
    array_reduce: (a, cb, initial = null) => entries(need('array_reduce', a, 'array')).reduce((carry, [, v]) => callable(cb)(carry, v), initial),
    array_slice: (a, offset, length = null, preserveKeys = false) => {
        const list = entries(need('array_slice', a, 'array'));
        const n = list.length;
        let from = toInt(offset);
        if (from < 0) from = Math.max(0, n + from);
        let to = n;
        if (length !== null && length !== undefined) {
            const l = toInt(length);
            to = l < 0 ? Math.max(from, n + l) : Math.min(n, from + l);
        }
        let next = 0;
        return fromEntries(list.slice(from, to).map(([k, v]) => (typeof k === 'number' && !preserveKeys ? [next++, v] : [k, v])));
    },
    array_reverse: (a, preserveKeys = false) => {
        let next = 0;
        return fromEntries(entries(need('array_reverse', a, 'array')).reverse().map(([k, v]) => (typeof k === 'number' && !preserveKeys ? [next++, v] : [k, v])));
    },
    /** PHP 8.3+: values that are not numbers count as 0 (with a warning), arrays are skipped. */
    array_sum: (a) => entries(need('array_sum', a, 'array')).reduce((s, [, v]) => s + lenientNumber(v, 0), 0),
    array_product: (a) => entries(need('array_product', a, 'array')).reduce((s, [, v]) => s * lenientNumber(v, 1), 1),
    array_unique: (a) => {
        const seen = [];
        return fromEntries(entries(need('array_unique', a, 'array')).filter(([, v]) => {
            const s = toStr(v);
            if (seen.includes(s)) return false;
            seen.push(s);
            return true;
        }));
    },
    array_column: (a, column, indexKey = null) => {
        const map = new Map();
        let next = 0;
        /** A key that is there, even holding null (not isset()). */
        const has = (row, k) => isArray(row) && (Array.isArray(row) ? typeof key(k) === 'number' && key(k) >= 0 && key(k) < row.length : row.has(key(k)));
        for (const [, row] of entries(need('array_column', a, 'array'))) {
            /** A null column takes every row whole, scalars too; a column, the rows that have it. */
            if (column !== null && !has(row, column)) continue;
            const value = column === null ? row : get(row, column);
            map.set(indexKey !== null && has(row, indexKey) ? key(get(row, indexKey)) : nextIndex(map), value);
        }
        return normalize(map);
    },
    array_combine: (keys, values) => {
        const k = entries(keys).map(([, v]) => v);
        const v = entries(values).map(([, x]) => x);
        if (k.length !== v.length) throw new PhpError('ValueError', 'array_combine(): Argument #1 ($keys) and argument #2 ($values) must have the same number of elements');
        return fromEntries(k.map((x, i) => [key(x), v[i]]));
    },
    /** Only int and string values can become keys; others are skipped (PHP warns). */
    array_flip: (a) => fromEntries(entries(need('array_flip', a, 'array'))
        .filter(([, v]) => typeof v === 'string' || Number.isInteger(v))
        .map(([k, v]) => [key(v), k])),
    array_fill: (start, n, value) => fromEntries(Array.from({ length: toInt(n) }, (_, i) => [toInt(start) + i, value])),
    array_pad: (a, size, value) => {
        const n = Math.abs(toInt(size)) - count(need('array_pad', a, 'array'));
        if (n <= 0) return a;
        const fill = Array(n).fill(value);
        return size < 0 ? fns.array_merge(fill, a) : fns.array_merge(a, fill);
    },
    array_chunk: (a, size, preserveKeys = false) => {
        const list = entries(need('array_chunk', a, 'array'));
        const out = [];
        for (let i = 0; i < list.length; i += toInt(size)) {
            const chunk = list.slice(i, i + toInt(size));
            out.push(preserveKeys ? fromEntries(chunk) : chunk.map(([, v]) => v));
        }
        return out;
    },
    array_diff: (a, ...others) => {
        const exclude = new Set(others.flatMap((o) => entries(o).map(([, v]) => toStr(v))));
        return fromEntries(entries(need('array_diff', a, 'array')).filter(([, v]) => !exclude.has(toStr(v))));
    },
    array_intersect: (a, ...others) => fromEntries(entries(need('array_intersect', a, 'array')).filter(([, v]) => others.every((o) => entries(o).some(([, x]) => toStr(x) === toStr(v))))),
    array_diff_key: (a, ...others) => fromEntries(entries(a).filter(([k]) => others.every((o) => !fns.array_key_exists(k, o)))),
    array_intersect_key: (a, ...others) => fromEntries(entries(a).filter(([k]) => others.every((o) => fns.array_key_exists(k, o)))),
    /** Each key as a string unless it is an int (1.5 is "1.5", an array "Array"), then as any key. */
    array_fill_keys: (keys, value) => fromEntries(entries(keys).map(([, k]) => [key(Number.isInteger(k) ? k : toStr(k)), value])),
    range: (start, end, step = 1) => range(start, end, step),
    json_encode: (v, flags = 0) => jsonEncode(v, toInt(flags)),
    json_decode: (s) => jsonDecode(toStr(s)),

    // by-reference functions: the compiler assigns the result back ([newArray, returnValue])
    sort: (a) => [sortEntries(entries(a), ([, x], [, y]) => cmp(x, y)).map(([, v]) => v), true],
    rsort: (a) => [sortEntries(entries(a), ([, x], [, y]) => -cmp(x, y)).map(([, v]) => v), true],
    usort: (a, cb) => [sortEntries(entries(a), userCompare(cb, ([, x]) => x)).map(([, v]) => v), true],
    uasort: (a, cb) => [fromEntries(sortEntries(entries(a), userCompare(cb, ([, x]) => x))), true],
    uksort: (a, cb) => [fromEntries(sortEntries(entries(a), userCompare(cb, ([k]) => k))), true],
    asort: (a) => [fromEntries(sortEntries(entries(a), ([, x], [, y]) => cmp(x, y))), true],
    arsort: (a) => [fromEntries(sortEntries(entries(a), ([, x], [, y]) => -cmp(x, y))), true],
    ksort: (a) => [fromEntries(sortEntries(entries(a), ([x], [y]) => cmp(x, y))), true],
    krsort: (a) => [fromEntries(sortEntries(entries(a), ([x], [y]) => -cmp(x, y))), true],
    shuffle: (a) => {
        const list = entries(a).map(([, v]) => v);
        for (let i = list.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [list[i], list[j]] = [list[j], list[i]];
        }
        return [list, true];
    },
    array_push: (a, ...values) => {
        let out = a;
        for (const v of values) out = push(out, v);
        return [out, count(out)];
    },
    array_pop: (a) => {
        const list = entries(need('array_pop', a, 'array'));
        if (list.length === 0) return [a, null];
        const [, value] = list.pop();
        const map = new Map(list);
        return [normalize(map), value];
    },
    array_shift: (a) => {
        const list = entries(need('array_shift', a, 'array'));
        if (list.length === 0) return [a, null];
        const [, value] = list.shift();
        let next = 0;
        return [fromEntries(list.map(([k, v]) => (typeof k === 'number' ? [next++, v] : [k, v]))), value];
    },
    array_unshift: (a, ...values) => {
        let next = 0;
        const map = new Map(values.map((v) => [next++, v]));
        for (const [k, v] of entries(a)) map.set(typeof k === 'number' ? next++ : k, v);
        return [normalize(map), map.size];
    },
    array_splice: (a, offset, length = null, replacement = []) => {
        const list = entries(need('array_splice', a, 'array'));
        const n = list.length;
        let from = toInt(offset);
        if (from < 0) from = Math.max(0, n + from);
        from = Math.min(from, n);
        let del = length === null || length === undefined ? n - from : toInt(length);
        if (del < 0) del = Math.max(0, n + del - from);
        const removed = list.splice(from, del, ...entries(toArr(replacement)).map(([, v]) => [0, v]));
        let next = 0;
        const out = fromEntries(list.map(([k, v]) => (typeof k === 'number' ? [next++, v] : [k, v])));
        let removedNext = 0;
        return [out, fromEntries(removed.map(([k, v]) => (typeof k === 'number' ? [removedNext++, v] : [k, v])))];
    },
};

/**
 * The functions' parameter types, checked as PHP checks them in coercive mode: a for array, s
 * string, i int, f float, n int|float, b bool, sa array|string, key an array key, k string|int|null,
 * c callable, m mixed; ?x also takes null; x... is every argument from there on.
 */
const PARAMETERS = {
    strlen: 's', mb_strlen: 's ?s', strtolower: 's', strtoupper: 's', mb_strtolower: 's ?s', mb_strtoupper: 's ?s',
    ucfirst: 's', lcfirst: 's', ucwords: 's s', str_contains: 's s', str_starts_with: 's s', str_ends_with: 's s',
    trim: 's s', ltrim: 's s', rtrim: 's s', str_repeat: 's i', str_pad: 's i s i', str_replace: 'sa sa sa', str_ireplace: 'sa sa sa',
    substr: 's i ?i', mb_substr: 's i ?i ?s', strpos: 's s i', stripos: 's s i', strrpos: 's s i', substr_count: 's s i ?i',
    strrev: 's', str_split: 's i', nl2br: 's b', explode: 's s i', sprintf: 's m...', number_format: 'n i ?s ?s',
    htmlspecialchars: 's i ?s b', abs: 'n', round: 'n i i', floor: 'n', ceil: 'n', intdiv: 'i i', fmod: 'f f', sqrt: 'f',
    intval: 'm i', random_int: 'i i',
    array_is_list: 'a', array_keys: 'a m b', array_values: 'a', array_key_exists: 'key a', array_key_first: 'a', array_key_last: 'a',
    in_array: 'm a b', array_search: 'm a b', array_merge: 'a...', array_filter: 'a ?c i', array_map: '?c a a...',
    array_reduce: 'a c m', array_slice: 'a i ?i b', array_reverse: 'a b', array_sum: 'a', array_product: 'a', array_unique: 'a i',
    array_column: 'a k k', array_combine: 'a a', array_flip: 'a', array_fill: 'i i m', array_pad: 'a i m', array_chunk: 'a i b',
    array_diff: 'a a...', array_intersect: 'a a...', array_diff_key: 'a a...', array_intersect_key: 'a a...', array_fill_keys: 'a m',
    range: 'r r n', json_encode: 'm i i', json_decode: 's ?b i i',
    sort: 'a i', rsort: 'a i', usort: 'a c', uasort: 'a c', uksort: 'a c', asort: 'a i', arsort: 'a i', ksort: 'a i', krsort: 'a i',
    shuffle: 'a', array_push: 'a m...', array_pop: 'a', array_shift: 'a', array_unshift: 'a m...', array_splice: 'a i ?i m',
};

const PARAMETER_TYPES = { a: 'array', s: 'string', i: 'int', f: 'float', n: 'int|float', b: 'bool', sa: 'array|string', key: 'string|int', k: 'string|int|null', r: 'string|int|float' };

const scalar = (v) => v === null || v === undefined || typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean';
const stringable = (v) => scalar(v) || (typeof v === 'object' && !isArray(v) && typeof v.toString === 'function' && v.toString !== Object.prototype.toString);

/** A value PHP passes to a parameter of the type (a numeric parameter takes a numeric string, not "2x" or ""). */
function accepts(type, v) {
    switch (type) {
        case 'a': return isArray(v);
        case 's': return stringable(v);
        case 'i': return scalar(v) && (typeof v !== 'string' || isNumericString(v)) && (typeof v !== 'number' || Number.isFinite(v));
        case 'f': case 'n': return scalar(v) && (typeof v !== 'string' || isNumericString(v));
        case 'b': case 'key': case 'k': case 'r': return scalar(v);
        case 'sa': return isArray(v) || stringable(v);
        default: return true;
    }
}

function checked(name, spec, fn) {
    const types = spec.split(' ');
    return (...args) => {
        for (let i = 0; i < args.length; i++) {
            let type = types[Math.min(i, types.length - 1)];
            if (i >= types.length && !type.endsWith('...')) break;
            type = type.replace('...', '');
            const v = args[i];
            const nullable = type.startsWith('?');
            if (nullable) type = type.slice(1);
            if ((nullable && (v === null || v === undefined)) || accepts(type, v)) continue;
            throw typeError(`${name}(): Argument #${i + 1} must be of type ${nullable ? '?' : ''}${PARAMETER_TYPES[type]}, ${typeName(v)} given`);
        }
        return fn(...args);
    };
}

for (const [name, spec] of Object.entries(PARAMETERS)) fns[name] = checked(name, spec, fns[name]);

/** implode($separator, $array) or implode($array): PHP 8's two forms. */
const implode = fns.implode;
fns.implode = fns.join = (separator, array) => {
    if (array === undefined) {
        if (!isArray(separator)) throw typeError(`implode(): Argument #1 ($array) must be of type array, ${typeName(separator)} given`);
        return implode('', separator);
    }
    if (!stringable(separator)) throw typeError(`implode(): Argument #1 ($separator) must be of type string, ${typeName(separator)} given`);
    if (!isArray(array)) throw typeError(`implode(): Argument #2 ($array) must be of type ?array, ${typeName(array)} given`);
    return implode(separator, array);
};

/** Functions whose first argument PHP takes by reference. */
export const BY_REF = new Set([
    'sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort', 'shuffle',
    'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_splice',
]);

/** Stable sort (PHP 8 sorts are stable). */
function sortEntries(list, compare) {
    /** PHP 8's sorts are stable: a tie keeps the original order. */
    const items = list.map((x, i) => ({ x, i }));
    zendSort(items, 0, items.length, (a, b) => compare(a.x, b.x) || (a.i > b.i ? 1 : a.i < b.i ? -1 : 0));
    return items.map(({ x }) => x);
}

/**
 * A comparison callback as PHP calls it: its result as an int's sign; false is asked again with
 * the arguments swapped (PHP deprecates returning a bool).
 */
function userCompare(cb, value) {
    const fn = callable(cb);
    return (a, b) => {
        const result = fn(value(a), value(b));
        if (result === false) return -Math.sign(toInt(fn(value(b), value(a))));
        return Math.sign(toInt(result));
    };
}

/**
 * zend_sort (Zend/zend_sort.c), step for step: insertion sort up to 16 items, quicksort above
 * (a median of 3 pivot, of 5 from 1024 items). The same comparisons in the same order, so a
 * comparison that is no ordering ($x + $y) gives what it gives in PHP.
 */
function zendSort(a, base, n, cmp) {
    while (true) {
        if (n <= 16) {
            insertSort(a, base, n, cmp);
            return;
        }
        const start = base;
        const end = start + n;
        const offset = n >> 1;
        let pivot = start + offset;
        if (n >> 10) {
            const delta = offset >> 1;
            sort5(a, start, start + delta, pivot, pivot + delta, end - 1, cmp);
        } else {
            sort3(a, start, pivot, end - 1, cmp);
        }
        swap(a, start + 1, pivot);
        pivot = start + 1;
        let i = pivot + 1;
        let j = end - 1;
        partition: while (true) {
            while (cmp(a[pivot], a[i]) > 0) {
                i++;
                if (i === j) break partition;
            }
            j--;
            if (j === i) break;
            while (cmp(a[j], a[pivot]) > 0) {
                j--;
                if (j === i) break partition;
            }
            swap(a, i, j);
            i++;
            if (i === j) break;
        }
        swap(a, pivot, i - 1);
        if (i - 1 - start < end - i) {
            zendSort(a, start, i - start - 1, cmp);
            base = i;
            n = end - i;
        } else {
            zendSort(a, i, end - i, cmp);
            n = i - start - 1;
        }
    }
}

function insertSort(a, start, n, cmp) {
    switch (n) {
        case 0: case 1: return;
        case 2: sort2(a, start, start + 1, cmp); return;
        case 3: sort3(a, start, start + 1, start + 2, cmp); return;
        case 4: sort4(a, start, start + 1, start + 2, start + 3, cmp); return;
        case 5: sort5(a, start, start + 1, start + 2, start + 3, start + 4, cmp); return;
    }
    const end = start + n;
    const sentry = start + 6;
    for (let i = start + 1; i < sentry; i++) {
        let j = i - 1;
        if (!(cmp(a[j], a[i]) > 0)) continue;
        while (j !== start) {
            j--;
            if (!(cmp(a[j], a[i]) > 0)) {
                j++;
                break;
            }
        }
        for (let k = i; k > j; k--) swap(a, k, k - 1);
    }
    for (let i = sentry; i < end; i++) {
        let j = i - 1;
        if (!(cmp(a[j], a[i]) > 0)) continue;
        while (true) {
            j -= 2;
            if (!(cmp(a[j], a[i]) > 0)) {
                j++;
                if (!(cmp(a[j], a[i]) > 0)) j++;
                break;
            }
            if (j === start) break;
            if (j === start + 1) {
                j--;
                if (cmp(a[i], a[j]) > 0) j++;
                break;
            }
        }
        for (let k = i; k > j; k--) swap(a, k, k - 1);
    }
}

function swap(a, i, j) {
    const t = a[i];
    a[i] = a[j];
    a[j] = t;
}

function sort2(a, i, j, cmp) {
    if (cmp(a[i], a[j]) > 0) swap(a, i, j);
}

function sort3(a, i, j, k, cmp) {
    if (!(cmp(a[i], a[j]) > 0)) {
        if (!(cmp(a[j], a[k]) > 0)) return;
        swap(a, j, k);
        if (cmp(a[i], a[j]) > 0) swap(a, i, j);
        return;
    }
    if (!(cmp(a[k], a[j]) > 0)) {
        swap(a, i, k);
        return;
    }
    swap(a, i, j);
    if (cmp(a[j], a[k]) > 0) swap(a, j, k);
}

function sort4(a, i, j, k, l, cmp) {
    sort3(a, i, j, k, cmp);
    if (cmp(a[k], a[l]) > 0) {
        swap(a, k, l);
        if (cmp(a[j], a[k]) > 0) {
            swap(a, j, k);
            if (cmp(a[i], a[j]) > 0) swap(a, i, j);
        }
    }
}

function sort5(a, i, j, k, l, m, cmp) {
    sort4(a, i, j, k, l, cmp);
    if (cmp(a[l], a[m]) > 0) {
        swap(a, l, m);
        if (cmp(a[k], a[l]) > 0) {
            swap(a, k, l);
            if (cmp(a[j], a[k]) > 0) {
                swap(a, j, k);
                if (cmp(a[i], a[j]) > 0) swap(a, i, j);
            }
        }
    }
}

function lenientNumber(v, skipped) {
    if (isArray(v)) return skipped;
    if (typeof v === 'string') return parseNumeric(v, true) ?? 0;
    return toFloat(v);
}

/**
 * range() as PHP 8.3 defines it: a string of one byte is a character ("0" too), a longer
 * numeric string a number, any other string its first byte ("" is 0). A character beside a
 * number is its number ("3") or 0 ("e"), and everything is 0 with a fractional step for
 * characters (PHP warns).
 */
function range(start, end, step) {
    const input = (v) => {
        if (typeof v !== 'string') return toNum(v);
        if (v === '') return 0;
        if (v.length > 1 && isNumericString(v)) return parseNumeric(v);
        return { byte: utf8(v)[0], number: isNumericString(v) ? parseNumeric(v) : 0 };
    };
    let a = input(start);
    let b = input(end);
    const by = toNum(step);
    const isChar = (v) => typeof v === 'object';
    let chars = isChar(a) || isChar(b);

    if (chars && (!isChar(a) || !isChar(b))) {
        if (isChar(a)) a = a.number;
        else b = b.number;
        chars = false;
    }
    if (chars && !Number.isInteger(by)) [a, b, chars] = [0, 0, false];
    if (chars) [a, b] = [a.byte, b.byte];

    if (by === 0) throw new PhpError('ValueError', 'range(): Argument #3 ($step) cannot be 0');
    if (by < 0 && a < b) throw new PhpError('ValueError', 'range(): Argument #3 ($step) must be greater than 0 for increasing ranges');
    const size = Math.abs(by);
    if (a !== b && size > Math.abs(b - a)) {
        throw new PhpError('ValueError', 'range(): Argument #3 ($step) must be less than the range spanned by argument #1 ($start) and argument #2 ($end)');
    }

    const n = Math.floor(Math.abs(b - a) / size + 1e-9) + 1;
    const direction = a <= b ? 1 : -1;
    return Array.from({ length: n }, (_, i) => {
        const v = a + direction * i * size;
        return chars ? fromUtf8(Uint8Array.of(v)) : v;
    });
}

function extreme(args, direction, fn) {
    /** Two arguments take PHP 8.4's own path, which settles a tie (7 and true) otherwise: min() gives the second, max() the first. */
    if (args.length === 2) {
        const order = cmp(args[0], args[1]);
        return direction < 0 ? (order < 0 ? args[0] : args[1]) : (order >= 0 ? args[0] : args[1]);
    }
    let values = args;
    if (args.length === 1) {
        if (!isArray(args[0])) throw typeError(`${fn}(): Argument #1 ($value) must be of type array, ${typeName(args[0])} given`);
        values = entries(args[0]).map(([, v]) => v);
        if (values.length === 0) throw new PhpError('ValueError', `${fn}(): Argument #1 ($value) must contain at least one element`);
    }
    return values.reduce((best, v) => (cmp(v, best) * direction > 0 ? v : best));
}

function trimWith(s, chars, left, right) {
    let set = ' \t\n\r\0\x0B';
    if (chars !== undefined && chars !== null) {
        set = toStr(chars).replace(/(.)\.\.(.)/g, (_, a, b) => {
            let out = '';
            for (let c = a.charCodeAt(0); c <= b.charCodeAt(0); c++) out += String.fromCharCode(c);
            return out;
        });
    }
    let start = 0;
    let end = s.length;
    if (left) while (start < end && set.includes(s[start])) start++;
    if (right) while (end > start && set.includes(s[end - 1])) end--;
    return s.slice(start, end);
}

function strReplace(search, replace, subject, insensitive) {
    if (!isArray(search) && isArray(replace)) {
        throw typeError(`str_${insensitive ? 'i' : ''}replace(): Argument #2 ($replace) must be of type string when argument #1 ($search) is a string`);
    }
    /** An array subject: each item as a string (an array in it is "Array"). */
    if (isArray(subject)) {
        return fromEntries(entries(subject).map(([k, v]) => [k, strReplace(search, replace, toStr(v), insensitive)]));
    }
    let s = toStr(subject);
    const searches = isArray(search) ? entries(search).map(([, v]) => toStr(v)) : [toStr(search)];
    const replaces = isArray(replace) ? entries(replace).map(([, v]) => toStr(v)) : null;
    searches.forEach((needle, i) => {
        if (needle === '') return;
        const replacement = replaces ? replaces[i] ?? '' : toStr(replace);
        /** str_ireplace() ignores the case of ASCII letters only (PHP 8.2+): FunctionParityTest. */
        const haystack = insensitive ? ASCII_LOWER(s) : s;
        const find = insensitive ? ASCII_LOWER(needle) : needle;
        let out = '';
        let from = 0;
        for (let at = haystack.indexOf(find); at !== -1; at = haystack.indexOf(find, from)) {
            out += s.slice(from, at) + replacement;
            from = at + find.length;
        }
        s = out + s.slice(from);
    });
    return s;
}

function bytePos(h, n, offset, last) {
    const hb = utf8(h);
    const nb = utf8(n);
    if (offset < 0) offset += hb.length;
    if (offset < 0 || offset > hb.length) throw new PhpError('ValueError', 'strpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)');
    const matchAt = (i) => nb.every((b, j) => hb[i + j] === b);
    if (last) {
        for (let i = hb.length - nb.length; i >= offset; i--) if (matchAt(i)) return i;
    } else {
        for (let i = offset; i <= hb.length - nb.length; i++) if (matchAt(i)) return i;
    }
    return false;
}

// ---------------------------------------------------------------- the wire format

/**
 * Values between PHP and JS: JSON, where every PHP array that is not a list is the object
 * {"$m": [[key, value], ...]}, so key order and integer keys survive. Mirrors
 * Nitro\Fusion\Support\Wire.
 */
export function decode(x) {
    if (Array.isArray(x)) return x.map(decode);
    if (x !== null && typeof x === 'object') {
        if (Array.isArray(x.$m)) return normalize(new Map(x.$m.map(([k, v]) => [key(k), decode(v)])));
        if (x.$u !== undefined && Object.keys(x).length === 1) return new Upload(x.$u);
        return normalize(new Map(Object.keys(x).map((k) => [key(k), decode(x[k])])));
    }
    return x;
}

export function encode(v) {
    if (v === undefined) return null;
    if (v instanceof Upload) return { $u: v.wire };
    if (Array.isArray(v)) return v.map(encode);
    if (v instanceof Map) return { $m: [...v.entries()].map(([k, x]) => [k, encode(x)]) };
    if (typeof v === 'number' && !Number.isFinite(v)) throw new PhpError('Error', 'INF and NAN cannot be sent to the server');
    return v;
}

// ---------------------------------------------------------------- uploads

/**
 * A file a fusion:model file input uploaded (Nitro\Fusion\Upload on the server): its signed
 * reference, sent back unchanged, and what a view may show of it. Upload::toWire() made it.
 */
export class Upload {
    constructor(wire) {
        this.wire = wire;
    }

    temporaryUrl() {
        if (this.wire.url === null || this.wire.url === undefined) {
            throw new PhpError('RuntimeException', `${this.wire.name} is not an image: only images have a temporary URL.`);
        }
        return this.wire.url;
    }

    getClientOriginalName() {
        return this.wire.name;
    }

    getClientOriginalExtension() {
        return this.wire.ext;
    }

    getSize() {
        return this.wire.size;
    }

    getMimeType() {
        return this.wire.mime;
    }
}

/** $value->temporaryUrl() and the other Upload methods (Globals::UPLOAD_METHODS), as PHP calls them. */
export function upload(v, method, args) {
    if (!(v instanceof Upload)) throw new PhpError('Error', `Call to a member function ${method}() on ${typeName(v)}`);
    return v[method](...args);
}
