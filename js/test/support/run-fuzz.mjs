// Differential fuzzing runner: stdin {programs: [{id, js, expected}]}, each a compiled method
// and what PHP did (its canonical form, see canon()); stdout [{id, actual}] for every program
// that did something else, did not compile as JavaScript, or did not finish in time.
import vm from 'node:vm';
import * as P from '../../src/php.js';
import { canon, readStdin } from './common.mjs';

const input = await readStdin();
const failures = [];

for (const program of input.programs) {
    let actual;
    try {
        const f = new Function('P', `return ${program.js};`)(P);
        const component = { f, p: null, $call() { throw new Error('server call'); }, $dispatch() {} };
        /** The method runs under a watchdog: a loop that never ends is a failure, not a hang. */
        const ret = vm.runInContext('run()', vm.createContext({ run: () => component.f() }), { timeout: 2000 });
        actual = canon([ret, component.p]);
    } catch (err) {
        actual = err instanceof P.PhpError ? `error ${err.name}` : `JS ${err.name}: ${err.message}`;
    }

    if (actual !== program.expected) failures.push({ id: program.id, actual });
}

process.stdout.write(JSON.stringify(failures));
