// The wire format as the browser runtime handles it: stdin {values: [{id, json}], states: [{id, envelope}]}.
// A value is decoded (P.decode, as a snapshot's data) and encoded again (P.encode, as an update
// is sent): its canonical form and the JSON sent. A state is read as the runtime reads a
// snapshot (readState): each property's canonical form.
import * as P from '../../src/php.js';
import { readState } from '../../src/runtime/component/snapshot.js';
import { canon, readStdin } from './common.mjs';

const input = await readStdin();

const values = (input.values ?? []).map(({ id, json }) => {
    const value = P.decode(JSON.parse(json));
    return { id, canon: canon(value), wire: JSON.stringify(P.encode(value)) };
});

const states = (input.states ?? []).map(({ id, envelope }) => {
    const { data } = readState(envelope);
    return { id, data: Object.fromEntries([...data].map(([name, value]) => [name, canon(value)])) };
});

process.stdout.write(JSON.stringify({ values, states }));
