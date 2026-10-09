/**
 * Nitro's dev overlay (dist/nitro-devtools.js), loaded by @nitroScripts in development only
 * (nitro.devtools: on with APP_DEBUG), never in production. A plugin on the public runtime
 * (Nitro.use()), loaded after it: the components on the page and their state, each request a
 * component sent (its time, the server's, its size), the page's data, the stores, and the errors.
 */
import { Overlay } from './overlay.js';

if (window.Nitro) new Overlay(window, window.Nitro).install();
