// Syntax check of compiled components: stdin {cases: [{id, js}]}, each a Nitro.define(...) call;
// stdout [{id, error}] for those that are not valid JavaScript (parsed, never run).
import vm from 'node:vm';
import { readStdin } from './common.mjs';

const { cases } = await readStdin();
const failures = [];

for (const { id, js } of cases) {
    try {
        new vm.Script(`(function (Nitro, P, B) {\n"use strict";\n${js}\n})`);
    } catch (error) {
        failures.push({ id, error: `${error.name}: ${error.message}` });
    }
}

process.stdout.write(JSON.stringify(failures));
