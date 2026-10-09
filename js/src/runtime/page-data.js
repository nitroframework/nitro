/**
 * A page's data as the server writes it into the page's HTML (Rendering\PageData): the globals
 * @nitroScripts gives, one block with each server-rendered component's signed state, by its id,
 * and what the runtime's own script tells it (Rendering\Scripts). The one place the runtime reads
 * any of them, from the page it starts on or a page a visit fetched. A component takes its state once; an island that starts later finds its own here.
 */
import { readState } from './component/snapshot.js';
import { ATTRIBUTES, selector, selectorOf } from './protocol.js';

/** id => signed state, of the server-rendered components not started yet. */
const states = new Map();

/** The runtime's own script, as @nitroScripts writes it (Rendering\Scripts): it carries what the runtime is told. */
const RUNTIME_SCRIPT = 'script[data-bundle]';

/** The runtime's script in a page (the document, or a page a visit fetched); null when it has none. */
export function runtimeScriptOf(page) {
    return page.querySelector(RUNTIME_SCRIPT);
}

/** Whether a script is the runtime's. */
export function isRuntimeScript(script) {
    return script.matches(RUNTIME_SCRIPT);
}

/**
 * What @nitroScripts tells the runtime on its script: the endpoint, app.js's version (bundle),
 * where a chunk is ({id} for its name), the page's CSRF token, the progress bar's options
 * (config/nprogress.php; null when it is off), and with fallbacks on, what a component that fails
 * to render shows (Rendering\Fallbacks; null when they are off), and whether visits change the
 * page in View Transitions (nitro.view_transitions).
 */
export function configOf(script) {
    return {
        endpoint: script.dataset.endpoint ?? '',
        bundle: script.dataset.bundle ?? '',
        chunks: script.dataset.chunks ?? '',
        csrf: script.dataset.csrf ?? '',
        progress: script.dataset.progress ? JSON.parse(script.dataset.progress) : null,
        fallback: script.dataset.fallback ?? null,
        transitions: script.dataset.viewTransitions === '1',
    };
}

/** The globals a page carries (the document, or a page a visit fetched); null when it has none or they cannot be read. */
export function globalsOf(page) {
    return jsonIn(page, ATTRIBUTES.globals);
}

/** The states a page carries, kept for its components until each takes its own. */
export function readStates(page) {
    for (const [id, envelope] of Object.entries(jsonIn(page, ATTRIBUTES.state) ?? {})) states.set(id, envelope);
}

/** Whether a script is one of those that carry a page's data. */
export function isPageDataScript(script) {
    return script.hasAttribute(ATTRIBUTES.globals) || script.hasAttribute(ATTRIBUTES.state);
}

/** The server-rendered components under root (root included) that have not started: their elements. */
export function withState(root) {
    const has = (el) => states.has(el.getAttribute(ATTRIBUTES.id));
    return [...(root.matches?.(selector('id')) && has(root) ? [root] : []), ...[...root.querySelectorAll(selector('id'))].filter(has)];
}

/** The name of the component an element's state is for, null when it cannot be read. */
export function nameOf(el) {
    try {
        return readState(stateOf(el)).name ?? null;
    } catch {
        return null;
    }
}

/** An element's signed state: the envelope (throws when it has none). */
export function stateOf(el) {
    const envelope = states.get(el.getAttribute(ATTRIBUTES.id));
    if (!envelope) throw new Error('Nitro: no state for this component');
    return envelope;
}

/** Its state taken: the component has it now. */
export function dropState(el) {
    states.delete(el.getAttribute(ATTRIBUTES.id));
}

/** The size of what a page carries (the dev overlay): the globals, and each component's state by id, in bytes. */
export function pageDataSizes(page) {
    const bytes = (text) => new TextEncoder().encode(text ?? '').length;
    const states = {};
    for (const [id, envelope] of Object.entries(jsonIn(page, ATTRIBUTES.state) ?? {})) states[id] = bytes(JSON.stringify(envelope));
    return { globals: bytes(page.querySelector(`script${selectorOf(ATTRIBUTES.globals)}`)?.textContent), states };
}

function jsonIn(page, attribute) {
    const script = page.querySelector(`script${selectorOf(attribute)}`);
    if (!script) return null;
    try {
        return JSON.parse(script.textContent);
    } catch {
        return null;
    }
}
