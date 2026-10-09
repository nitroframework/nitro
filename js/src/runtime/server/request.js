/**
 * Every request the runtime sends to the server: a visit's page (and a prefetch), a reload of a
 * page's properties, a #[Server] call, an upload. The one place that sends (the guards keep it
 * so): Nitro's headers on each, the plugins' (Nitro.use(): on('request')) on each a component
 * sends, and expired state (419) handled alike for a call and an upload.
 */
import { session } from '../../laravel/blade.js';
import { HEADERS, PATHS, TIMING } from '../protocol.js';

/** Nitro's headers when it asks a page for its data (a visit, a reload): a Nitro page answers with it, not its HTML. */
function pageHeaders(runtime) {
    return {
        Accept: 'application/json',
        [HEADERS.navigate]: '1',
        [HEADERS.version]: runtime.config.bundle ?? '',
        [HEADERS.once]: runtime.onceHeader(),
    };
}

/** Nitro's headers on a request to its endpoint (a call, an upload). */
function endpointHeaders(runtime) {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': session.token,
        [HEADERS.call]: '1',
    };
}

/** A visit: the page's data (data; id: the page rendered early, which the server's state keeps), or its HTML. */
export function visit(runtime, url, data, id = null) {
    return runtime.window.fetch(url, {
        headers: data ? { ...pageHeaders(runtime), ...(id ? { [HEADERS.id]: id } : {}) } : { Accept: 'text/html, application/xhtml+xml' },
        credentials: 'same-origin',
    });
}

/** A reload of the page's properties (all of them when none are named), for the component that asks. */
export function reload(runtime, component, href, only) {
    /** Plugins hear it as a call of $reload, and may add headers; Nitro's own are set after theirs. */
    const headers = {
        ...runtime.plugins.request(component, '$reload', {}),
        ...pageHeaders(runtime),
        [HEADERS.partial]: component.id,
    };
    if (only.length > 0) headers[HEADERS.only] = only.join(',');
    return runtime.window.fetch(href, { credentials: 'same-origin', headers });
}

/**
 * The component's state is no longer good (another session, an expired lifetime, a logout, a new
 * APP_KEY): the page loads again, with fresh state. A nitro:expired listener can preventDefault()
 * to do something else.
 */
function expired(runtime, component, status) {
    if (status === 419 && runtime.emit('nitro:expired', { component: component.name, status: 419 }, true)) runtime.window.location.reload();
}

/**
 * What a request took, for plugins (the dev overlay): ms in all, the answer's size when it was read
 * (text), and in development the server's time and its database's (Server-Timing).
 */
export function timingOf(runtime, response, started, text = null) {
    const timing = { ms: runtime.window.performance.now() - started, bytes: text === null ? null : new TextEncoder().encode(text).length };
    for (const metric of (response?.headers.get(HEADERS.timing) ?? '').split(',')) {
        const [name, ...params] = metric.trim().split(';').map((part) => part.trim());
        const dur = params.find((param) => param.startsWith('dur='));
        if (!dur) continue;
        if (name === TIMING.app) timing.serverMs = Number(dur.slice(4));
        if (name === TIMING.db) timing.dbMs = Number(dur.slice(4));
    }
    return timing;
}

/** A #[Server] call (method null: $refresh) with its body. */
export async function call(runtime, component, method, body) {
    /** Plugins may add headers (Nitro.use(): on('request')); Nitro's own are set after theirs. */
    const headers = runtime.plugins.request(component, method, {});
    const response = await runtime.window.fetch(`${runtime.config.endpoint}/${PATHS.update}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            ...headers,
            'Content-Type': 'application/json',
            ...endpointHeaders(runtime),
            [HEADERS.once]: runtime.onceHeader(),
        },
        body: JSON.stringify(body),
    });
    expired(runtime, component, response.status);
    return response;
}

/**
 * An upload for the component (XMLHttpRequest, which reports upload progress; fetch() does not):
 * progress(0-100) as it goes, done(status, text) when it ends (status 0: the server could not be
 * reached). Plugins hear it as a call of $upload, and may add headers.
 */
export function sendFiles(runtime, component, form, progress, done) {
    const request = new runtime.window.XMLHttpRequest();
    const started = runtime.window.performance.now();
    request.open('POST', `${runtime.config.endpoint}/${PATHS.upload}`, true);
    const headers = { ...runtime.plugins.request(component, '$upload', {}), ...endpointHeaders(runtime) };
    for (const [name, value] of Object.entries(headers)) request.setRequestHeader(name, value);
    request.upload.onprogress = (e) => {
        if (e.lengthComputable) progress(Math.round((e.loaded * 100) / e.total));
    };
    const end = (status, text) => {
        const answer = status === 0 ? null : { headers: { get: (name) => request.getResponseHeader(name) } };
        runtime.plugins.response(component, '$upload', status, timingOf(runtime, answer, started, typeof text === 'string' ? text : null));
        expired(runtime, component, status);
        done(status, text);
    };
    request.onload = () => end(request.status, request.responseText);
    request.onerror = () => end(0, '');
    request.send(form);
}
