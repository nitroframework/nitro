/**
 * #[Server] calls: POST {endpoint}/update with the component's signed snapshot, the values
 * changed in the browser since, and the method. One call at a time per component (in order);
 * the answer replaces the state, keeping anything changed in the browser meanwhile.
 */
import * as P from '../../php.js';
import { HEADERS, PATHS, SIGNALS } from '../protocol.js';
import { webUrl } from '../navigation/navigate.js';
import { applyEffects } from './effects.js';

export class Network {
    constructor(runtime, config) {
        this.runtime = runtime;
        this.config = config;
        this.queues = new Map();
    }

    /**
     * Call a #[Server] method (null: just sync the state, as $refresh does). A failure is reported
     * (console and fusion:error) here, once; the promise still rejects for code that awaits it.
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
     * for its data (X-Fusion-Partial: its id; X-Fusion-Only: the properties, all when none), then
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
        /** Plugins hear it as a call of $reload, and may add headers; Fusion's own are set after theirs. */
        const headers = {
            ...this.runtime.plugins.request(component, '$reload', {}),
            Accept: 'application/json',
            [HEADERS.navigate]: '1',
            [HEADERS.partial]: component.id,
            [HEADERS.version]: this.config.bundle ?? '',
            [HEADERS.once]: this.runtime.onceHeader(),
        };
        if (only.length > 0) headers[HEADERS.only] = only.join(',');

        if (!deferred) this.runtime.loading(component, '$reload', true);
        let response;
        try {
            response = await fetch(href, { credentials: 'same-origin', headers });
        } catch (error) {
            throw new ServerCallError('$reload: the server could not be reached', 0, String(error?.message ?? error));
        } finally {
            if (!deferred) this.runtime.loading(component, '$reload', false);
            this.runtime.plugins.response(component, '$reload', response?.status ?? 0);
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
        if (!response.ok || page?.fusion !== 1) {
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
            store: component.isStore && component.snapshot === null ? component.name : undefined,
            partials: partials.length > 0 ? partials : undefined,
            updates: component.updates(),
            call: method === null ? null : { method, params: args.map(P.encode) },
            children: children || undefined,
            stores: stores.size > 0 ? Object.fromEntries([...stores].map(([name, store]) => [name, { snapshot: store.snapshot, updates: store.updates() }])) : undefined,
        };

        this.runtime.loading(component, method, true);
        this.runtime.emit('fusion:request', { component: component.name, method });
        let response;
        try {
            /** Plugins may add headers (Fusion.use(): on('request')); Fusion's own are set after theirs. */
            const headers = this.runtime.plugins.request(component, method, {});
            response = await fetch(`${this.config.endpoint}/${PATHS.update}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    ...headers,
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.config.csrf,
                    [HEADERS.call]: '1',
                    [HEADERS.once]: this.runtime.onceHeader(),
                },
                body: JSON.stringify(body),
            });
        } catch (error) {
            throw new ServerCallError(`${method ?? '$refresh'}(): the server could not be reached`, 0, String(error?.message ?? error));
        } finally {
            this.runtime.loading(component, method, false);
            this.runtime.emit('fusion:response', { component: component.name, method, status: response?.status ?? 0 });
            this.runtime.plugins.response(component, method, response?.status ?? 0);
        }

        const text = await response.text();
        /**
         * The state is no longer good (another session, an expired lifetime, a logout, a new
         * APP_KEY): the page loads again, with fresh state. A fusion:expired listener can
         * preventDefault() to do something else.
         */
        if (response.status === 419) {
            if (this.runtime.emit('fusion:expired', { component: component.name, status: 419 }, true)) this.runtime.window.location.reload();
        }
        if (!response.ok) {
            throw new ServerCallError(`${method ?? '$refresh'}(): the server answered ${response.status}`, response.status, text);
        }

        let answer;
        try {
            answer = JSON.parse(text);
        } catch {
            /** A 200 that is not Fusion's answer: PHP printing a warning or an error page, a proxy. */
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

/** A #[Server] call that failed; status 0 when the server was not reached. */
export class ServerCallError extends Error {
    constructor(message, status, body) {
        super(`Fusion: ${message}`);
        this.name = 'ServerCallError';
        this.status = status;
        this.body = body;
    }
}
