// Parity runner: stdin [{id, fn, args, expected: {value}|{error}}] in wire format; stdout the cases
// where php.js does not do what PHP did.
import * as P from '../../src/php.js';
import { readdirSync, readFileSync } from 'node:fs';
import { readStdin, same, describe } from './common.mjs';

/** js/functions (Viewi's PhpJsFunctions, ported), registered as a bundle registers them. */
const functions = new URL('../../functions/', import.meta.url);
for (const file of readdirSync(functions)) {
    const name = file.replace(/\.js$/, '');
    P.fns[name] = new Function('P', readFileSync(new URL(file, functions), 'utf8') + `\nreturn ${name};`)(P);
}

const OPS = {
    'op:add': P.add,
    'op:sub': P.sub,
    'op:mul': P.mul,
    'op:div': P.div,
    'op:mod': P.mod,
    'op:pow': P.pow,
    'op:concat': P.concat,
    'op:eq': P.eq,
    'op:identical': P.identical,
    'op:cmp': P.cmp,
    'op:lt': P.lt,
    'op:gt': P.gt,
    'op:lte': P.lte,
    'op:gte': P.gte,
    'op:bool': P.bool,
    'op:toStr': P.toStr,
    'op:toInt': P.toInt,
    'op:toFloat': P.toFloat,
    'op:e': P.e,
    'op:inc': P.inc,
    'op:dec': P.dec,
    'op:get': P.get,
    'op:set': P.set,
    'op:push': P.push,
    /** The cases write [] as null in a path (the wire has no APPEND). */
    'op:setIn': (base, path, v) => P.setIn(base, path.map((k) => (k === null ? P.APPEND : k)), v),
    'op:unsetIn': P.unsetIn,
    'op:isset': P.isset,
    'op:empty': P.empty,
    'op:literal': P.arrayLiteral,
};

const LIBM = new Set(['acos', 'acosh', 'asin', 'asinh', 'atan', 'atan2', 'atanh', 'cos', 'cosh', 'sin', 'sinh', 'tan', 'tanh', 'exp', 'expm1', 'log', 'log10', 'log1p', 'hypot']);

const cases = await readStdin();
const failures = [];

for (const c of cases) {
    /** Str::slug is js/functions' Str_slug. */
    const fn = OPS[c.fn] ?? P.fns[c.fn.replace(/^Str::/, 'Str_')];
    let args = c.args.map(P.decode);
    if (c.fn === 'op:literal') {
        /** PHP's "no key" arrives as null; the literal builder takes undefined. */
        args = [args[0].map(([k, v, spread]) => [k === null ? undefined : k, v, spread])];
    }
    let actual;
    try {
        if (!fn) throw new Error(`no such function: ${c.fn}`);
        const result = fn(...args);
        actual = { value: P.BY_REF.has(c.fn) ? result : result };
    } catch (err) {
        actual = { error: err instanceof P.PhpError ? err.name : `JS ${err.name}: ${err.message}` };
    }
    const expected = c.expected.error !== undefined ? c.expected : { value: P.decode(c.expected.value) };
    /**
     * The C library's transcendental functions (PHP's) and V8's may differ in the last digit, as
     * PHP's own builds do between platforms: those compare within a few units in the last place.
     */
    const close = LIBM.has(c.fn) && typeof actual.value === 'number' && typeof expected.value === 'number'
        && Math.abs(actual.value - expected.value) <= 4 * Number.EPSILON * Math.max(Math.abs(actual.value), Math.abs(expected.value));
    const ok = expected.error !== undefined ? actual.error === expected.error : actual.error === undefined && (close || same(actual.value, expected.value));
    if (!ok) {
        failures.push({ id: c.id, fn: c.fn, expected: describe(expected), actual: describe(actual) });
    }
}

process.stdout.write(JSON.stringify(failures));
