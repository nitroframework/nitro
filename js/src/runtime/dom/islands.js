/**
 * Islands: components placed in a Blade view with fusion:hydrate start later than the page,
 * marked on their element as fusion:hydrate="idle" (the browser is idle), "visible" or
 * "visible:200px" (in view, or that close), "interaction" (the first click, focus, key or input
 * in it) or "media:(query)" (the media query matches). Until then it is the server's HTML.
 *
 * The click that starts an interaction island is held, and given again once the island is live,
 * so its handler runs. What is typed in an island before it is live (its chunk loading) is kept:
 * starting puts its fields as the state says, so they get it back, and fusion:model hears it.
 */
import { ATTRIBUTES, selector } from '../protocol.js';

/** The islands under root that start later: elements with fusion:hydrate. */
export function islandsIn(root) {
    return [...(root.matches?.(selector('hydrate')) ? [root] : []), ...root.querySelectorAll(selector('hydrate'))];
}

/** Call start() once the island's moment comes. start() returns a promise: the island is live. */
export function whenToStart(island, window, start) {
    const spec = island.getAttribute(ATTRIBUTES.hydrate) ?? '';
    const colon = spec.indexOf(':');
    const when = colon === -1 ? spec : spec.slice(0, colon);
    const option = colon === -1 ? '' : spec.slice(colon + 1);
    /** Fields typed in while it waits: field => what it had. */
    const typed = new Map();
    const record = (event) => {
        if ('value' in event.target) typed.set(event.target, event.target.value);
    };
    island.addEventListener('input', record, true);

    let started = null;
    const go = () =>
        (started ??= start().then(() => {
            island.removeEventListener('input', record, true);
            for (const [field, value] of typed) {
                if (!field.isConnected) continue;
                field.value = value;
                field.dispatchEvent(new window.Event('input', { bubbles: true }));
            }
        }));

    if (when === 'idle') {
        if (window.requestIdleCallback) window.requestIdleCallback(go, { timeout: 2000 });
        else window.setTimeout(go, 200);
        return;
    }

    if (when === 'visible') {
        if (!window.IntersectionObserver) return void go();
        const observer = new window.IntersectionObserver(
            (entries) => {
                if (!entries.some((entry) => entry.isIntersecting)) return;
                observer.disconnect();
                go();
            },
            { rootMargin: option || '0px' },
        );
        observer.observe(island);
        return;
    }

    if (when === 'media') {
        const query = window.matchMedia?.(option);
        if (!query || query.matches) return void go();
        const changed = (event) => {
            if (!event.matches) return;
            query.removeEventListener('change', changed);
            go();
        };
        query.addEventListener('change', changed);
        return;
    }

    if (when === 'interaction') {
        const events = ['click', 'focusin', 'keydown', 'input'];
        const first = (event) => {
            for (const type of events) island.removeEventListener(type, first, true);

            /** The click waits for the island: its handler runs then. */
            if (event.type === 'click') {
                event.preventDefault();
                event.stopPropagation();
            }

            go().then(() => {
                const target = event.target;
                if (event.type === 'click' && target?.isConnected) target.dispatchEvent(new event.constructor(event.type, event));
            });
        };
        for (const type of events) island.addEventListener(type, first, true);
        return;
    }

    go();
}
