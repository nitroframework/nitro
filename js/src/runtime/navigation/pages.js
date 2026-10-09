/**
 * The pages a visit can show without asking the server: answers a link prefetched
 * (nitro:navigate.prefetch) and pages already visited, for back and forward. Part of the
 * navigator (navigate.js).
 *
 * It holds a page's data as the server sent it, in memory only: never in localStorage or the
 * history entry, which the browser keeps on disk, so a reload or another visitor at the same
 * computer starts without it. It is short-lived, and any #[Server] call empties it: a page shown
 * from it is never older than the visitor's own last change.
 *
 *   prefetched   kept for the link's time (nitro:navigate.prefetch.60s; 30s by default), taken
 *                by the visit it was for; a prefetch still on its way is waited for, not sent twice
 *   visited      kept for back and forward, five minutes; without its flash messages, which were
 *                for the first showing only
 */
const VISITED_FOR = 5 * 60 * 1000;
const MAX_PAGES = 30;

export class PageCache {
    constructor(clock = () => Date.now()) {
        this.clock = clock;
        /** url (without its #hash) => { kind, until, answer } or { kind, until, pending } */
        this.entries = new Map();
    }

    /** A prefetch: its answer to come, kept for ms once here (a failed one is forgotten). */
    prefetch(url, answer, ms) {
        const key = keyOf(url);
        const entry = { kind: 'prefetched', until: this.clock() + ms, pending: answer };
        this.set(key, entry);
        answer.then((result) => {
            if (this.entries.get(key) !== entry) return;
            if (result?.page?.nitro === 1 && !result.page.partial) {
                delete entry.pending;
                entry.answer = result;
            } else {
                this.entries.delete(key);
            }
        });
    }

    /** Whether a prefetch for the URL is here or on its way. */
    prefetching(url) {
        const entry = this.fresh(keyOf(url));
        return entry?.kind === 'prefetched';
    }

    /** A page visited: kept for back and forward, without its flash messages. */
    visited(url, answer) {
        if (answer?.page?.nitro !== 1 || answer.page.partial) return;
        const page = copy(answer.page);
        page.flash = [];
        this.set(keyOf(url), { kind: 'visited', until: this.clock() + VISITED_FOR, answer: { ...answer, page } });
    }

    /**
     * The answer a visit to the URL can use: a prefetch's (taken: it is for one visit), and, moving
     * through the history, a visited page's. Null for none. A copy: the page is the runtime's to change.
     */
    async take(url, history) {
        const key = keyOf(url);
        const entry = this.fresh(key);
        if (!entry || (entry.kind === 'visited' && !history)) return null;
        if (entry.kind === 'prefetched') this.entries.delete(key);
        const answer = entry.pending ? await entry.pending : entry.answer;
        return answer?.page?.nitro === 1 ? { ...answer, page: copy(answer.page) } : null;
    }

    /** Everything forgotten: a #[Server] call changed something these pages may show. */
    clear() {
        this.entries.clear();
    }

    fresh(key) {
        const entry = this.entries.get(key);
        if (entry && entry.until <= this.clock()) {
            this.entries.delete(key);
            return null;
        }
        return entry ?? null;
    }

    set(key, entry) {
        this.entries.delete(key);
        this.entries.set(key, entry);
        if (this.entries.size > MAX_PAGES) this.entries.delete(this.entries.keys().next().value);
    }
}

/** A page's data is JSON as the server sent it: a JSON copy is a whole one. */
function copy(page) {
    return JSON.parse(JSON.stringify(page));
}

function keyOf(url) {
    const parsed = new URL(url);
    parsed.hash = '';
    return parsed.href;
}
