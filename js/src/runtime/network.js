/**
 * #[Server] calls: POST {endpoint}/update with the component's signed snapshot, the values
 * changed in the browser since, and the method. One call at a time per component (in order);
 * the answer replaces the state, keeping anything changed in the browser meanwhile.
 */
import * as P from '../php.js';

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

    async send(component, method, args) {
        if (component.destroyed) return null;
        const sent = new Map(component.data);
        const body = {
            snapshot: component.snapshot,
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
