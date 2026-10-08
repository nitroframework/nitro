/**
 * Fusion's dev overlay (dist/fusion-devtools.js), loaded by @fusionScripts in development only
 * (fusion.devtools: on with APP_DEBUG), never in production. A plugin on the public runtime
 * (Fusion.use()), loaded after it: the components on the page and their state, each request a
 * component sent (its time, the server's, its size), the page's data, the stores, and the errors.
 */
import { Overlay } from './overlay.js';

if (window.Fusion) new Overlay(window, window.Fusion).install();
