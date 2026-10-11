// #[Html] in the browser (no stdin): an envelope kept as the signed part alone, its HTML kept
// apart, kept again when the server leaves it out, replaced when it changed; what a call sends
// back in its place. stdout: what went wrong.
import { fingerprints, keepEnvelope } from '../../src/runtime/component/snapshot.js';

const failures = [];
const expect = (what, actual, wanted) => {
    if (JSON.stringify(actual) !== JSON.stringify(wanted)) failures.push(`${what}: ${JSON.stringify(actual)}, not ${JSON.stringify(wanted)}`);
};

const component = {};

expect('first: the names whose HTML is new', keepEnvelope(component, { state: 's1', checksum: 'c1', html: { body: ['f1', '<p>one</p>'] } }), ['body']);
expect('the snapshot kept is the signed part alone', component.snapshot, { state: 's1', checksum: 'c1' });
expect('the HTML is kept apart', component.html.get('body'), { fingerprint: 'f1', value: '<p>one</p>' });
expect('a call sends its fingerprint', fingerprints(component), { body: 'f1' });

expect('left out: nothing changed', keepEnvelope(component, { state: 's2', checksum: 'c2' }), []);
expect('left out: the HTML kept', component.html.get('body').value, '<p>one</p>');
expect('left out: the new signed part', component.snapshot, { state: 's2', checksum: 'c2' });

expect('changed: its name', keepEnvelope(component, { state: 's3', checksum: 'c3', html: { body: ['f2', '<p>two</p>'] } }), ['body']);
expect('changed: the new HTML', component.html.get('body').value, '<p>two</p>');

const plain = {};
keepEnvelope(plain, null);
expect('no envelope (made in the browser)', plain.snapshot, null);
expect('no #[Html]: no fingerprints sent', fingerprints(plain), undefined);

process.stdout.write(JSON.stringify(failures));
