/**
 * #[Server] calls: POST {endpoint}/update with the component's signed snapshot, the values
 * changed in the browser since, and the method. One call at a time per component (in order);
 * the answer replaces the state, keeping anything changed in the browser meanwhile.
 */
import * as P from '../../php.js';
import { HEADERS, SIGNALS } from '../protocol.js';
import { fingerprintsField } from '../component/snapshot.js';
import { webUrl } from '../navigation/navigate.js';
import { applyEffects } from './effects.js';
import { call, reload, timingOf } from './request.js';

export class Network {
    constructor(runtime, config) {
        this.runtime = runtime;
        this.config = config;
        this.queues = new Map();
    }

    /**
     * Call a #[Server] method (null: just sync the state, as $refresh does). A failure is reported
     * (console and nitro:error) here, once; the promise still rejects for code that awaits it.
     */
    /** leaving: the component's goodbye, a #[Server] destroyed(): sent though it has left the page. */
    call(component, method, args = [], { leaving = false } = {}) {
        const previous = this.queues.get(component) ?? Promise.resolve();
        const next = previous.catch(() => {}).then(() => this.send(component, method, args, false, leaving));
        next.catch((error) => this.runtime.report(component, error));
        this.queues.set(component, next);
        return next;
    }

    /**
     * Reload a page's properties from its route, as Inertia's partial reloads ($reload,
     * $this->reload(), and its deferred properties once it is shown): the page's URL asked again
     * for its data (X-Nitro-Partial: its id; X-Nitro-Only: the properties, all when none), then
     * the named properties taken from it. In the component's queue, after the calls before it.
     */
    reload(component, only = [], deferred = false, url = null) {
        const previous = this.queues.get(component) ?? Promise.resolve();
        const next = previous.catch(() => {}).then(() => this.load(component, only, deferred, url));
        next.catch((error) => this.runtime.report(component, error));
        this.queues.set(component, next);
        return next;
    }

    /** url: a function giving the URL to ask ($loadMore: the next page; null: nothing to load), else the page's. */
    async load(component, only, deferred, url = null) {
        if (component.destroyed) return;
        const window = this.runtime.window;
        const href = url ? url() : window.location.href;
        if (href === null) return;
        const sent = new Map(component.data);
        if (!deferred) this.runtime.loading(component, '$reload', true);
        const started = window.performance.now();
        let response;
        try {
            response = await reload(this.runtime, component, href, only);
        } catch (error) {
            throw new ServerCallError('$reload: the server could not be reached', 0, String(error?.message ?? error));
        } finally {
            if (!deferred) this.runtime.loading(component, '$reload', false);
            this.runtime.plugins.response(component, '$reload', response?.status ?? 0, timingOf(this.runtime, response, started));
        }
        if (component.destroyed) return;

        /** Deployed since the page loaded: load it in full, with the new components. */
        if (response.status === 409) {
            window.location.href = webUrl(response.headers.get(HEADERS.location) || window.location.href, window.location.href)?.href ?? window.location.href;
            return;
        }

        const text = await response.text();
        let page = null;
        try {
            page = JSON.parse(text);
        } catch {
            // reported below
        }
        if (!response.ok || page?.nitro !== 1) {
            throw new ServerCallError(`$reload: the server answered ${response.status}`, response.status, text);
        }
        if (component.destroyed) return;

        this.runtime.receive(page, component);
        if (deferred) {
            only.forEach((name) => component.deferred.delete(name));
            component.dirty.add(SIGNALS.deferred);
        }
        /** Children with mount() the reloaded data places: made by the server, with the page. */
        this.runtime.placement.withProvided(page.children, () => component.applyReload(page.snapshot, only, sent, page.partial ?? null));
    }

    /**
     * children: also ask for the signed state of the children its view places that need one, for
     * children made here (adopt()).
     */
    async send(component, method, args, children = false, leaving = false) {
        if (component.destroyed && !leaving) return null;
        /** Made here, by a render in the browser: it has no signed state for the server yet (a store: the server makes it). */
        if (component.snapshot === null && !component.isStore) await this.adopt(component);
        if (component.destroyed && !leaving) return null;
        const sent = new Map(component.data);
        const partials = component.partials;
        /** The stores it uses, as they are here: the server's methods read and change them too. */
        const stores = new Map(Object.values(component.definition.stores ?? {}).map((name) => [name, this.runtime.stores.named(name)]));
        const storesSent = new Map([...stores].map(([name, store]) => [name, new Map(store.data)]));
        const body = {
            snapshot: component.snapshot,
            /** Its #[Html] values' fingerprints, not the HTML: the server leaves out what still matches. */
            ...fingerprintsField(component),
            store: component.isStore && component.snapshot === null ? component.name : undefined,
            partials: partials.length > 0 ? partials : undefined,
            updates: component.updates(),
            call: method === null ? null : { method, params: args.map(P.encode) },
            children: children || undefined,
            stores: stores.size > 0 ? Object.fromEntries([...stores].map(([name, store]) => [name, { snapshot: store.snapshot, updates: store.updates() }])) : undefined,
        };

        this.runtime.loading(component, method, true);
        this.runtime.emit('nitro:request', { component: component.name, method });
        const started = this.runtime.window.performance.now();
        let response;
        try {
            response = await call(this.runtime, component, method, body);
        } catch (error) {
            throw new ServerCallError(`${method ?? '$refresh'}(): the server could not be reached`, 0, String(error?.message ?? error));
        } finally {
            this.runtime.loading(component, method, false);
            this.runtime.emit('nitro:response', { component: component.name, method, status: response?.status ?? 0 });
            if (!response) this.runtime.plugins.response(component, method, 0, timingOf(this.runtime, response, started));
        }

        const text = await response.text();
        this.runtime.plugins.response(component, method, response.status, timingOf(this.runtime, response, started, text));
        if (!response.ok) {
            /** With fallbacks on, a call that failed answers with the component's fallback: it shows in its place. */
            const fallback = fallbackIn(text);
            if (fallback !== null) component.fail(fallback);
            throw new ServerCallError(`${method ?? '$refresh'}(): the server answered ${response.status}`, response.status, text);
        }

        let answer;
        try {
            answer = JSON.parse(text);
        } catch {
            /** A 200 that is not Nitro's answer: PHP printing a warning or an error page, a proxy. */
            throw new ServerCallError(`${method ?? '$refresh'}(): the server's answer is not JSON`, response.status, text);
        }

        const { snapshot, effects = {} } = answer;
        this.runtime.receive(answer, component);
        /** The new snapshot has the partial reloads sent with the call. */
        component.partials = component.partials.filter((partial) => !partials.includes(partial));
        /** The stores it used, as the server left them. */
        for (const [name, envelope] of Object.entries(answer.stores ?? {})) {
            stores.get(name)?.applySnapshot(envelope, storesSent.get(name));
        }
        /** Children with mount() its new state places: made by the server, with the answer. */
        this.runtime.placement.withProvided(answer.children, () => component.applySnapshot(snapshot, sent));
        for (const [id, envelope] of Object.entries(answer.children ?? {})) {
            const child = this.runtime.instances.get(id);
            if (child && child.snapshot === null) child.adopt(envelope);
        }
        applyEffects(this.runtime, component, effects);

        return effects.returns === undefined ? null : P.decode(effects.returns);
    }

    /**
     * A component made in the browser (a row a render added) takes its signed state from the
     * server before its first call: its parent is rendered there, from the parent's own signed
     * state and what changed here, and the child's state is the one that render gives it. What
     * changed in the child here is sent with the call, as for any component.
     */
    async adopt(component) {
        const parent = component.parent;
        if (!parent || parent.destroyed) {
            throw new ServerCallError(`[${component.name}] was made in the browser, outside a component: it has no signed state for its #[Server] methods`, 0, '');
        }
        const previous = this.queues.get(parent) ?? Promise.resolve();
        const next = previous.catch(() => {}).then(() => this.send(parent, null, [], true));
        this.queues.set(parent, next);
        await next;
        if (component.snapshot === null && !component.destroyed) {
            throw new ServerCallError(`[${component.name}] was not placed by its parent's view on the server, so it has no signed state for its #[Server] methods`, 0, '');
        }
    }
}

/** The fallback a failed call's answer carries (Rendering\Fallbacks), null when it has none. */
function fallbackIn(text) {
    try {
        const fallback = JSON.parse(text)?.fallback;
        return typeof fallback === 'string' ? fallback : null;
    } catch {
        return null;
    }
}

/** A #[Server] call that failed; status 0 when the server was not reached. */
export class ServerCallError extends Error {
    constructor(message, status, body) {
        super(`Nitro: ${message}`);
        this.name = 'ServerCallError';
        this.status = status;
        this.body = body;
    }
}
