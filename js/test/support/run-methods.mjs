// Parity runner for compiled methods: stdin {methods: {name: "function ..."}, properties, cases};
// stdout the cases where the compiled method does not do what PHP did.
import * as P from '../../src/php.js';
import { readStdin, same } from './common.mjs';

const input = await readStdin();
const source = Object.entries(input.methods).map(([name, code]) => `${JSON.stringify(name)}: ${code}`).join(',\n');

let methods;
try {
    methods = new Function('P', `return {\n${source}\n};`)(P);
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
        actual = { value: new Map([['state', state], ['return', ret === undefined ? null : ret]]) };
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
