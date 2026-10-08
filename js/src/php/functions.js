/** The PHP functions compiled code may call (fns), checked as PHP checks their arguments, and their helpers. Part of php.js. */
import { PhpError, entries, fromEntries, isArray, isMap, key, nextIndex, normalize, typeError } from './core.js';
import { count, empty, get, push, set } from './arrays.js';
import { bool, isNumericString, parseNumeric, toArr, toFloat, toInt, toNum, toStr, typeName } from './conversions.js';
import { ASCII_LOWER, ASCII_UPPER, cmp, e, eq, fromUtf8, identical, pow, utf8 } from './operators.js';
import { jsonDecode, jsonEncode } from './json.js';
import { number_format, round, sprintf } from './numbers.js';
import { sortEntries, userCompare } from './sort.js';

// ---------------------------------------------------------------- the functions components may call

function need(fn, v, type, n = 1) {
    if (type === 'array' && !isArray(v)) {
        throw typeError(`${fn}(): Argument #${n} must be of type array, ${typeName(v)} given`);
    }
    return v;
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

export const scalar = (v) => v === null || v === undefined || typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean';
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
