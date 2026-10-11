// Finding a view by its name, as the adapters do (stdin: {app: [paths], namespaces: {name: [paths]},
// cases: [[view, path or null]]}): the app's glob and namespaced() ones together, each view looked
// up with findView(). stdout: the cases that found another file, or found one where none was due.
import { readStdin } from './common.mjs';
import { findView, namespaced } from '../../adapters/core.js';

const input = await readStdin();
const module = (path) => () => Promise.resolve({ default: path });

let views = Object.fromEntries(input.app.map((path) => [path, module(path)]));
for (const [namespace, paths] of Object.entries(input.namespaces)) {
    views = { ...views, ...namespaced(namespace, Object.fromEntries(paths.map((path) => [path, module(path)]))) };
}

const failures = [];
for (const [view, expected] of input.cases) {
    const found = await findView(views, view, 'vue').then((path) => path, () => null);
    if (found !== expected) failures.push(`${view}: ${found ?? 'none'}, not ${expected ?? 'none'}`);
}

process.stdout.write(JSON.stringify(failures));
