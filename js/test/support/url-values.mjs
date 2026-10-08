// [[type, raw], ...] on stdin: what the runtime reads from each ([fits, value]), as JSON on stdout.
import { readUrlValue } from '../../src/runtime/url-values.js';

let input = '';
for await (const chunk of process.stdin) input += chunk;

const results = JSON.parse(input).map(([type, raw]) => {
    const value = readUrlValue(type, raw);
    return value === undefined ? [false, null] : [true, value];
});

process.stdout.write(JSON.stringify(results));
