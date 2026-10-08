// The runtime's URL guard (navigate.js webUrl): stdin {allowed, refused}; stdout the URLs it got wrong.
import { webUrl } from '../../src/runtime/navigation/navigate.js';
import { readStdin } from './common.mjs';

const input = await readStdin();
const base = 'http://localhost/page';
console.error = () => {};

const wrong = [
    ...input.allowed.filter((href) => webUrl(href, base) === null).map((href) => `refused ${href}`),
    ...input.refused.filter((href) => webUrl(href, base) !== null).map((href) => `allowed ${href}`),
];

process.stdout.write(JSON.stringify(wrong));
