/** PHP's conversions: types, truthiness, (string), (int), (float), numeric strings. Part of php.js. */
import { PhpError, entries, isArray, typeError } from './core.js';
import { count } from './arrays.js';
import { e, sign } from './operators.js';
import { Upload } from './wire.js';
import { EnumCase } from './enums.js';

// ---------------------------------------------------------------- conversions

export function typeName(v) {
    if (v === null || v === undefined) return 'null';
    if (v instanceof Upload) return 'Nitro\\Fusion\\Upload';
    if (v instanceof EnumCase) return v.$class;
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
    if (v instanceof EnumCase) throw new PhpError('Error', `Object of class ${v.$class} could not be converted to string`);
    if (typeof v === 'object' && typeof v.toString === 'function' && v.toString !== Object.prototype.toString) return v.toString();
    throw new PhpError('Error', `Object of class ${v?.constructor?.name ?? 'object'} could not be converted to string`);
}

const NUMERIC = /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/;
const LEADING_NUMERIC = /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/;

export const isNumericString = (s) => typeof s === 'string' && NUMERIC.test(s);

/** A number from a numeric string, or null. */
export function parseNumeric(s, leading = false) {
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
