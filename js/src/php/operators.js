/** PHP's operators (comparison, arithmetic, ...), and strings as bytes. Part of php.js. */
import { PhpError, entries, isArray, normalize, toMap, typeError } from './core.js';
import { count, get, set } from './arrays.js';
import { bool, isNumericString, toInt, toNum, toStr, typeName } from './conversions.js';
import { decode, encode } from './wire.js';
import { EnumCase } from './enums.js';

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
    /** An enum case equals itself only (PHP: enums are not ordered; any other comparison is false). */
    if (a instanceof EnumCase || b instanceof EnumCase) return a === b ? 0 : 1;
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

export function sign(n) {
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

export const ASCII_LOWER = (s) => s.replace(/[A-Z]/g, (c) => c.toLowerCase());
export const ASCII_UPPER = (s) => s.replace(/[a-z]/g, (c) => c.toUpperCase());

/** htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $double), as Laravel's e(). */
export function e(v, double = true) {
    if (v === null || v === undefined) return '';
    /** Laravel's e() echoes a backed enum's value. */
    if (v instanceof EnumCase && 'value' in v) v = v.value;
    if (isArray(v)) throw typeError('htmlspecialchars(): Argument #1 ($string) must be of type string, array given');
    const s = toStr(v);
    return (double ? s.replace(/&/g, '&amp;') : s.replace(/&(?!(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);)/gi, '&amp;'))
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
