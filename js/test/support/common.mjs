import * as P from '../../src/php.js';

export async function readStdin() {
    /** Decoded once: a character split between two chunks stays one. */
    const chunks = [];
    for await (const chunk of process.stdin) chunks.push(chunk);
    const data = Buffer.concat(chunks).toString('utf8');

    return JSON.parse(data);
}

/** Structural equality of PHP values (numbers compare by value: JS has one number type). */
export function same(a, b) {
    if (a === undefined) a = null;
    if (b === undefined) b = null;
    if (typeof a === 'number' && typeof b === 'number') {
        return Object.is(a, b) || a === b || (Number.isNaN(a) && Number.isNaN(b));
    }
    if (Array.isArray(a) || a instanceof Map || Array.isArray(b) || b instanceof Map) {
        if (Array.isArray(a) !== Array.isArray(b) || (a instanceof Map) !== (b instanceof Map)) return false;
        const ea = P.entries(a);
        const eb = P.entries(b);
        return ea.length === eb.length && ea.every(([k, v], i) => eb[i][0] === k && same(v, eb[i][1]));
    }
    return a === b;
}

export function describe(result) {
    if (result.error !== undefined) return { error: result.error };
    /** NAN and INF have no wire form: shown as themselves. */
    const encode = (v) => (typeof v === 'number' && !Number.isFinite(v) ? String(v) : Array.isArray(v) ? v.map(encode) : v instanceof Map ? { $m: [...v].map(([k, x]) => [k, encode(x)]) } : v);
    return { value: JSON.stringify(encode(result.value)) };
}

/** A value as text both sides write the same way (tests/Fuzz/Fuzzer::canon). */
export function canon(v) {
    if (v === null || v === undefined) return 'null';
    if (v === true || v === false) return String(v);
    if (typeof v === 'number') return `n:${P.toStr(v)}`;
    if (typeof v === 'string') return `s:${JSON.stringify(v)}`;
    if (Array.isArray(v) || v instanceof Map) {
        const entries = P.entries(v);
        if (entries.every(([k], i) => k === i)) return `[${entries.map(([, x]) => canon(x)).join(',')}]`;
        return `{${entries.map(([k, x]) => `${typeof k === 'number' ? `n:${k}` : `s:${JSON.stringify(k)}`}=>${canon(x)}`).join(',')}}`;
    }
    return `object ${typeof v}`;
}
