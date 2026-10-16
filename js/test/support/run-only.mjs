// only() (js/vite.js): the include React's and Solid's plugins take when an app has both, against
// ids as Vite gives them (absolute, / or \). stdin: anything. stdout: its default folders
// (OneHomeTest compares them with config/nitro.php), and what went wrong.
import { readStdin } from './common.mjs';
import { only, folders } from '../../vite.js';

await readStdin();

const failures = [];
const check = (label, ok) => ok || failures.push(label);

const react = only('react');
check('a view under resources/views/react', react.test('C:/app/resources/views/react/post-editor.jsx'));
check('a view under resources/js/react, in a subfolder, .tsx', react.test('/srv/app/resources/js/react/blog/editor.tsx'));
check('Windows separators', react.test('C:\\app\\resources\\views\\react\\card.jsx'));
check('not Solid\'s views', !react.test('C:/app/resources/views/solid/post-editor.jsx'));
check('a script of its own beside the views, .js', react.test('C:/app/resources/views/react/helpers.js'));
check('not a stylesheet in its folder', !react.test('C:/app/resources/views/react/card.css'));
check('not a folder whose name only ends the same', !react.test('C:/app/resources/views/not-react/card.jsx'));

const solid = only('solid', 'resources/frontend/solid', './modules\\billing\\solid/');
check('a folder given', solid.test('C:/app/resources/frontend/solid/chart.jsx'));
check('a folder given with ./ and \\', solid.test('/srv/app/modules/billing/solid/invoice.tsx'));
check('not the default folders once folders are given', !solid.test('C:/app/resources/views/solid/chart.jsx'));

let refused = null;
try {
    only('preact');
} catch (error) {
    refused = error.message;
}
check('a framework it knows no folders of, without folders, is refused', refused !== null && refused.includes("only('preact', "));
check('with folders, it is fine', only('preact', 'resources/views/preact').test('C:/app/resources/views/preact/a.jsx'));

process.stdout.write(JSON.stringify({ folders, failures }));
