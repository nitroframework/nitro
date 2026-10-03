/**
 * #[Server] calls: POST {endpoint}/update with the component's signed snapshot, the values
 * changed in the browser since, and the method. One call at a time per component (in order);
 * the answer replaces the state, keeping anything changed in the browser meanwhile.
 */
import * as P from '../php.js';
import { readState } from './component.js';
import { ErrorBag } from './errors.js';

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
    call(component, method, args = []) {
        const previous = this.queues.get(component) ?? Promise.resolve();
        const next = previous.catch(() => {}).then(() => this.send(component, method, args));
        next.catch((error) => this.runtime.report(component, error));
        this.queues.set(component, next);
        return next;
    }

    /**
     * Reload a page's properties from its route or controller, as Inertia's partial reloads ($reload,
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
        const headers = {
            Accept: 'application/json',
            'X-Fusion-Navigate': '1',
            'X-Fusion-Partial': component.id,
            'X-Fusion-Version': this.config.bundle ?? '',
            'X-Fusion-Once': this.runtime.onceHeader(),
        };
        if (only.length > 0) headers['X-Fusion-Only'] = only.join(',');

        if (!deferred) this.runtime.loading(component, '$reload', true);
        let response;
        try {
            response = await fetch(href, { credentials: 'same-origin', headers });
        } catch (error) {
            throw new ServerCallError('$reload: the server could not be reached', 0, String(error?.message ?? error));
        } finally {
            if (!deferred) this.runtime.loading(component, '$reload', false);
        }
        if (component.destroyed) return;

        /** Deployed since the page loaded: load it in full, with the new components. */
        if (response.status === 409) {
            window.location.href = response.headers.get('X-Fusion-Location') || window.location.href;
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
            component.dirty.add('$deferred');
        }
        /** Children with mount() the reloaded data places: made by the server, with the page. */
        this.runtime.provided = new Map(Object.entries(page.children ?? {}));
        try {
            component.applyReload(page.snapshot, only, sent, page.partial ?? null);
        } finally {
            this.runtime.provided.clear();
        }
    }

    /**
     * $post(url, data, options), $put, $patch, $delete: a request to a controller's route, as
     * Inertia's router.post() (a view page's actions; any page's). The data goes as JSON, with the
     * CSRF token; the page's headers go too (X-Fusion-Navigate, X-Fusion-Partial: its id), so the
     * page the controller answers with, or redirects to (`return back()`), comes back as page data:
     *
     *  - the same page: its props are taken as a partial reload takes them (merge props merged),
     *    all of its #[Locked] properties (a view page's props) unless options.only names some;
     *    the page's own state stays, but options.reset puts the properties it names back to their
     *    defaults (a form once it is sent); validation errors from before are cleared
     *  - a 422 (validation failed): its errors become $errors, and nothing else changes
     *  - another page (a redirect elsewhere): a fusion:navigate visit to it
     */
    submit(component, method, url, data = null, options = null) {
        const previous = this.queues.get(component) ?? Promise.resolve();
        const next = previous.catch(() => {}).then(() => this.request(component, method, url, data, options));
        next.catch((error) => this.runtime.report(component, error));
        this.queues.set(component, next);
        return next;
    }

    async request(component, method, url, data, options) {
        if (component.destroyed) return;
        const window = this.runtime.window;
        const href = new URL(P.toStr(url), window.location.href).href;
        const list = (key) => (options === null || options === undefined ? null : P.get(options, key));
        const props = component.definition.props;
        const only = list('only') !== null
            ? P.entries(list('only')).map(([, name]) => P.toStr(name))
            : Object.keys(props).filter((name) => props[name].locked);
        const reset = list('reset') === null ? [] : P.entries(list('reset')).map(([, name]) => P.toStr(name));
        const sent = new Map(component.data);

        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': this.config.csrf,
            'X-Fusion-Navigate': '1',
            'X-Fusion-Partial': component.id,
            'X-Fusion-Version': this.config.bundle ?? '',
            'X-Fusion-Once': this.runtime.onceHeader(),
        };
        if (only.length > 0) headers['X-Fusion-Only'] = only.join(',');
        if (data !== null && data !== undefined) headers['Content-Type'] = 'application/json';

        const loading = '$' + method.toLowerCase();
        this.runtime.loading(component, loading, true);
        let response;
        try {
            response = await fetch(href, {
                method,
                credentials: 'same-origin',
                headers,
                body: data === null || data === undefined ? undefined : P.fns.json_encode(data),
            });
        } catch (error) {
            throw new ServerCallError(`${loading}(): the server could not be reached`, 0, String(error?.message ?? error));
        } finally {
            this.runtime.loading(component, loading, false);
        }
        if (component.destroyed) return;

        if (response.status === 409) {
            window.location.href = response.headers.get('X-Fusion-Location') || window.location.href;
            return;
        }
        if (response.status === 419) {
            this.runtime.emit('fusion:expired', { component: component.name, status: 419 });
        }

        const text = await response.text();
        let answer = null;
        try {
            answer = JSON.parse(text);
        } catch {
            // not JSON: below
        }

        /** Validation failed: Laravel's messages, by field, are the page's $errors. */
        if (response.status === 422 && answer?.errors) {
            component.errors = new ErrorBag(new Map(Object.entries(answer.errors)));
            component.dirty.add('$errors');
            component.update();
            return;
        }
        if (!response.ok) {
            throw new ServerCallError(`${loading}(): the server answered ${response.status}`, response.status, text);
        }

        /** Another page, or not a page: go there (a redirect elsewhere), or leave the page as it is. */
        if (answer?.fusion !== 1 || readState(answer.snapshot).name !== component.name) {
            if (response.redirected || answer?.fusion === 1) this.runtime.navigator.visit(response.url);
            return;
        }

        this.runtime.receive(answer, component);
        this.runtime.provided = new Map(Object.entries(answer.children ?? {}));
        try {
            component.applyReload(answer.snapshot, only, sent, answer.partial ?? null);
        } finally {
            this.runtime.provided.clear();
        }
        if (component.errors.any()) {
            component.errors = new ErrorBag();
            component.dirty.add('$errors');
        }
        if (reset.length > 0) component.reset(reset);
        component.update();
    }

    async send(component, method, args) {
        if (component.destroyed) return null;
        const sent = new Map(component.data);
        const partials = component.partials;
        const body = {
            snapshot: component.snapshot,
            partials: partials.length > 0 ? partials : undefined,
            updates: component.updates(),
            call: method === null ? null : { method, params: args.map(P.encode) },
        };

        this.runtime.loading(component, method, true);
        this.runtime.emit('fusion:request', { component: component.name, method });
        let response;
        try {
            response = await fetch(`${this.config.endpoint}/update`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.config.csrf,
                    'X-Fusion': '1',
                    'X-Fusion-Once': this.runtime.onceHeader(),
                },
                body: JSON.stringify(body),
            });
        } catch (error) {
            throw new ServerCallError(`${method ?? '$refresh'}(): the server could not be reached`, 0, String(error?.message ?? error));
        } finally {
            this.runtime.loading(component, method, false);
            this.runtime.emit('fusion:response', { component: component.name, method, status: response?.status ?? 0 });
        }

        const text = await response.text();
        if (response.status === 419) {
            this.runtime.emit('fusion:expired', { component: component.name, status: 419 });
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
        component.applySnapshot(snapshot, sent);
        this.runtime.effects(component, effects);

        return effects.returns === undefined ? null : P.decode(effects.returns);
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
