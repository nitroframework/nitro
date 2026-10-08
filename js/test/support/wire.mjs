// Values as Wire::encode() sends them, on stdin (a list): each decoded as the browser holds it,
// then encoded again as the browser sends it back; the list on stdout.
import { decode, encode } from '../../src/php/wire.js';

let input = '';
for await (const chunk of process.stdin) input += chunk;

process.stdout.write(JSON.stringify(JSON.parse(input).map((value) => encode(decode(value)))));
