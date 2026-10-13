/** json_encode() and json_decode(), as PHP's defaults have them. Part of php.js. */
import { PhpError, entries, isArray, key, normalize } from './core.js';
import { e } from './operators.js';
import { EnumCase } from './enums.js';

// ---------------------------------------------------------------- JSON (PHP json_encode defaults)

export function jsonEncode(v, flags = 0) {
    const HEX_TAG = 1;
    const HEX_AMP = 2;
    const HEX_APOS = 4;
    const HEX_QUOT = 8;
    const FORCE_OBJECT = 16;
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
        if (x instanceof EnumCase) {
            if (!('value' in x)) throw new PhpError('JsonException', `Non-backed enums have no default serialization`);
            return walk(x.value, level);
        }
        if (typeof x === 'number') {
            if (!Number.isFinite(x)) throw new PhpError('JsonException', 'Inf and NaN cannot be JSON encoded');
            return Number.isInteger(x) && Math.abs(x) < 1e15 ? String(x) : String(x).replace('e+', 'e+');
        }
        if (typeof x === 'string') return str(x);
        const sep = flags & PRETTY ? ': ' : ':';
        /** JSON_FORCE_OBJECT: a list too is an object, its indexes its keys. */
        if (Array.isArray(x) && flags & FORCE_OBJECT) return walk(new Map(x.map((y, i) => [i, y])), level);
        if (Array.isArray(x)) {
            if (x.length === 0) return '[]';
            return '[' + x.map((y) => indent(level + 1) + walk(y, level + 1)).join(',') + indent(level) + ']';
        }
        if (x instanceof Map) {
            if (x.size === 0) return flags & FORCE_OBJECT ? '{}' : '[]';
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
