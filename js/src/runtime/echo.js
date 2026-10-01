/**
 * Broadcast events, Livewire's way: #[On('echo:orders,OrderPlaced')] on a method subscribes the
 * component through the application's Laravel Echo (window.Echo) while it is on the page.
 *
 *   echo:channel,Event               a public channel           Echo.channel(...).listen(...)
 *   echo-private:channel,Event       a private channel          Echo.private(...).listen(...)
 *   echo-presence:channel,Event      a presence channel         Echo.join(...).listen(...)
 *   echo-presence:channel,here       its members: here, joining, leaving
 *   echo-notification:channel        notifications              Echo.private(...).notification(...)
 *
 * A {property} (or {property.key}) in the channel is the component's value when it subscribes:
 * echo-private:orders.{order.id},OrderShipped. The method gets the event's data (#[Server]
 * methods too, as a call). When the component leaves the page, its listeners stop; channels
 * stay joined, as other components may listen on them.
 */
import * as P from '../php.js';

const PATTERN = /^echo(?:-(private|presence|notification))?:([^,]+)(?:,(.+))?$/;

export class Broadcasts {
    constructor(runtime, window) {
        this.runtime = runtime;
        this.window = window;
        /** component => list of {channel, event, callback, notification} */
        this.subscriptions = new Map();
        this.warned = false;
    }

    /** Subscribe the component's echo listeners, once it is on the page. */
    subscribe(component) {
        const keys = Object.keys(component.definition.listeners ?? {}).filter((key) => key.startsWith('echo'));
        if (keys.length === 0 || this.subscriptions.has(component)) return;

        const echo = this.window.Echo;
        if (!echo) {
            if (!this.warned) {
                this.warned = true;
                this.runtime.report(component, new Error(`Fusion: [${component.name}] listens to broadcasts (#[On('echo:...')]), which needs Laravel Echo: window.Echo is not set`));
            }
            return;
        }

        const subscriptions = [];
        for (const key of keys) {
            const match = key.match(PATTERN);
            if (!match) continue;
            const [, type, pattern, event] = match;
            const name = pattern.replace(/\{([\w.]+)\}/g, (_, path) => P.toStr(component.getPath(path)));
            const callback = (payload) => this.receive(component, key, payload);

            try {
                if (type === 'notification') {
                    const channel = echo.private(name);
                    channel.notification(callback);
                    subscriptions.push({ channel, callback, notification: true });
                    continue;
                }

                const channel = type === 'private' ? echo.private(name) : type === 'presence' ? echo.join(name) : echo.channel(name);

                if (type === 'presence' && ['here', 'joining', 'leaving'].includes(event)) {
                    channel[event](callback);
                    subscriptions.push({ channel, callback, members: true });
                } else {
                    channel.listen(event, callback);
                    subscriptions.push({ channel, event, callback });
                }
            } catch (error) {
                this.runtime.report(component, error);
            }
        }
        this.subscriptions.set(component, subscriptions);
    }

    /** Stop the component's listeners (it left the page). */
    unsubscribe(component) {
        for (const subscription of this.subscriptions.get(component) ?? []) {
            try {
                if (subscription.notification) subscription.channel.stopListeningForNotification?.(subscription.callback);
                else if (subscription.event) subscription.channel.stopListening?.(subscription.event, subscription.callback);
                /** here, joining and leaving have no way to stop: receive() ignores a component that is gone. */
            } catch {
                // the channel is already gone
            }
        }
        this.subscriptions.delete(component);
    }

    /** An event for the component's listeners: browser methods run, #[Server] methods are called. */
    receive(component, key, payload) {
        if (component.destroyed) return;
        const data = P.decode(payload ?? null);

        for (const method of component.definition.listeners[key] ?? []) {
            if (component.definition.server.includes(method)) {
                component.ctx.$call(method, [data]).catch((error) => this.runtime.report(component, error));
                continue;
            }
            try {
                component.ctx[method](data);
            } catch (error) {
                this.runtime.report(component, error);
            }
        }
    }
}
