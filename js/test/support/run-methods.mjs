// Parity runner for compiled methods: stdin {methods: {name: "function ..."}, properties, cases, classes?};
// classes: the #[Browser] classes the methods call (Nitro.classes[class] = {...}, as the bundle defines them);
// stdout the cases where the compiled method does not do what PHP did.
import * as P from '../../src/php.js';
import { readStdin, same } from './common.mjs';

const input = await readStdin();
const source = Object.entries(input.methods).map(([name, code]) => `${JSON.stringify(name)}: ${code}`).join(',\n');

const Nitro = { classes: Object.create(null) };

let methods;
try {
    new Function('P', 'Nitro', input.classes ?? '')(P, Nitro);
    methods = new Function('P', 'Nitro', `return {\n${source}\n};`)(P, Nitro);
} catch (err) {
    process.stdout.write(JSON.stringify([{ id: -1, method: '(compile)', expected: 'valid JavaScript', actual: `${err.message}\n${source}` }]));
    process.exit(0);
}

const failures = [];

for (const c of input.cases) {
    const component = { ...methods, $call() { throw new Error('server call'); }, $dispatch() {} };
    const initial = P.decode(c.state);
    if (initial instanceof Map) {
        for (const [name, value] of initial) component[name] = value;
    }

    let actual;
    try {
        const ret = component[c.method](...c.args.map(P.decode));
        const state = new Map(input.properties.map((p) => [p, component[p] === undefined ? null : component[p]]));
        /** As the values would travel (an enum case as its value, as Wire::encode() sends PHP's). */
        actual = { value: P.decode(P.encode(new Map([['state', state], ['return', ret === undefined ? null : ret]]))) };
    } catch (err) {
        actual = { error: err instanceof P.PhpError ? err.name : `JS ${err.name}: ${err.message}` };
    }

    const expected = c.expected.error !== undefined ? c.expected : { value: P.decode(c.expected.value) };
    const ok = expected.error !== undefined
        ? actual.error === expected.error
        : actual.error === undefined && same(actual.value, expected.value);

    if (!ok) {
        const show = (r) => (r.error !== undefined ? `error ${r.error}` : JSON.stringify(P.encode(r.value)));
        failures.push({ id: c.id, method: c.method, expected: show(expected), actual: show(actual) });
    }
}

process.stdout.write(JSON.stringify(failures));
