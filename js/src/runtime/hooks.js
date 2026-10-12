/**
 * Hooks: the runtime's moments, each announced here and nowhere else. Its listeners run
 * (Nitro.hook(name, listener), and plugins' on()), then its window event, if it has one, is
 * dispatched; through 2.x under its earlier name too. Each hook(), and each on(), returns a
 * function that removes the listener.
 *
 *   init                    before the page's components start: register directives, engines and
 *                           plugins. { Nitro }                         document: nitro:init (was nitro:starting)
 *   initialized             the first page's components are all live. { Nitro }      document: nitro:initialized
 *   component.init          a component made, before its first render. The component
 *   component.initialized   a component started in the page (its first render). The component
 *                           (plugins' component)
 *   component.rendered      after each render of a component. The component (plugins' render)
 *   component.destroyed     a component left the page, whoever took it out. The component
 *   store.initialized       a store was made in this tab. The store (plugins' store)
 *   store.updated           a store's values changed (here, from the server, from another tab). The store
 *   beforeRequest           before a request is sent: a #[Server] call (kind call, its method), a
 *                           reload (reload, $reload), an upload (upload, $upload).
 *                           { component, method, kind, headers }: headers may be added to; preventDefault()
 *                           sends nothing                              nitro:before-request (a call's: nitro:request)
 *   afterRequest            its answer arrived (status 0: none came): how long it took, its size
 *                           when known, and in development the server's time and its database's;
 *                           cancelled when a beforeRequest listener stopped it.
 *                           { component, method, kind, status, cancelled, ms, bytes, serverMs, dbMs }
 *                                                                      nitro:after-request (a call's: nitro:response)
 *   beforeNavigate          a nitro:navigate visit starts. { url, cancelable }: preventDefault() stops a
 *                           visit from a link or Nitro.visit(); back and forward cannot be (the
 *                           address has moved)                         nitro:before-navigate (was nitro:navigating)
 *   afterNavigate           the visit is over: its page shown, or cancelled. { url, cancelled }
 *                                                                      nitro:after-navigate (was nitro:navigated)
 *   flash                   flash data came, for the page. { flash }  nitro:flash
 *   error                   something failed. { component, message, status, body }    nitro:error
 *   expired                 the state is no longer good (419): the page loads again, unless a
 *                           listener calls preventDefault(). { component, status }     nitro:expired
 *
 * Each before has its after, a cancelled one too. A listener gets components and stores as
 * plugins see them (plugins.js); a window event's detail has the component's name. A cancelable
 * hook's listener gets cancelable and preventDefault() (which does nothing when it is not); its
 * window event, under its own name, can be cancelled as well. A listener of
 * component.initialized or store.initialized added later hears those already there too.
 */

/** A request's kind: a #[Server] call, a reload of a page's properties, an upload. */
export const REQUEST_KINDS = Object.freeze({ call: 'call', reload: 'reload', upload: 'upload' });

/** Each hook: its window event (on the document for init), whether it can be stopped, and the names it had before 2.x's hooks. */
const HOOKS = {
    init: { event: 'nitro:init', was: 'nitro:starting', onDocument: true },
    initialized: { event: 'nitro:initialized', onDocument: true },
    'component.init': {},
    'component.initialized': { plugin: 'component' },
    'component.rendered': { plugin: 'render' },
    'component.destroyed': {},
    'store.initialized': { plugin: 'store' },
    'store.updated': {},
    beforeRequest: { event: 'nitro:before-request', was: 'nitro:request', wasFor: (payload) => payload.kind === REQUEST_KINDS.call, plugin: 'request', cancelable: () => true },
    afterRequest: { event: 'nitro:after-request', was: 'nitro:response', wasFor: (payload) => payload.kind === REQUEST_KINDS.call, plugin: 'response' },
    beforeNavigate: { event: 'nitro:before-navigate', was: 'nitro:navigating', cancelable: (payload) => payload.cancelable },
    afterNavigate: { event: 'nitro:after-navigate', was: 'nitro:navigated' },
    flash: { event: 'nitro:flash' },
    error: { event: 'nitro:error' },
    expired: { event: 'nitro:expired', cancelable: () => true },
};

/** The hooks' names, for Nitro's own code to name them by: hook(HOOK.afterRequest, ...), HOOK.storeInitialized for store.initialized. */
export const HOOK = Object.freeze(Object.fromEntries(Object.keys(HOOKS).map((name) => [name.replace(/\.(\w)/g, (_, letter) => letter.toUpperCase()), name])));

/** A plugin's on() names before 2.x's hooks: component, render, store, request, response. */
const PLUGIN_NAMES = Object.fromEntries(Object.entries(HOOKS).filter(([, hook]) => hook.plugin).map(([name, hook]) => [hook.plugin, name]));

export class Hooks {
    constructor(runtime) {
        this.runtime = runtime;
        /** hook => Set of listeners */
        this.listeners = new Map(Object.keys(HOOKS).map((name) => [name, new Set()]));
        /** The components that have been announced initialized. */
        this.started = new WeakSet();
    }

    /** A listener for a hook (or, for a plugin's on(), its name before 2.x's hooks); caller: what was called, for its errors. */
    hook(name, listener, caller = 'Nitro.hook()') {
        const hook = PLUGIN_NAMES[name] ?? name;
        if (!this.listeners.has(hook)) throw new Error(`${caller}: there is no hook "${name}" (${Object.keys(HOOKS).join(', ')})`);
        if (typeof listener !== 'function') throw new Error(`${caller}: '${name}' takes a function`);
        this.listeners.get(hook).add(listener);

        /** Added after they started: the components and stores already there. */
        if (hook === HOOK.componentInitialized) {
            for (const component of this.runtime.components()) {
                if (this.started.has(component) && !component.destroyed) this.call(hook, listener, component, this.facade(component));
            }
        }
        if (hook === HOOK.storeInitialized) {
            for (const store of this.runtime.stores.values()) this.call(hook, listener, store, this.facade(store));
        }

        return () => this.listeners.get(hook).delete(listener);
    }

    /**
     * The moment: its listeners, then its window event (and the earlier one). The payload's
     * component (a target: a component or a store) is given to listeners as plugins see it, to a
     * window event by its name. Whether it goes on: false when a listener of a cancelable one
     * called preventDefault().
     */
    announce(name, payload = {}, target = null) {
        const hook = HOOKS[name];
        const cancelable = Boolean(hook.cancelable?.(payload));
        let prevented = false;
        const given = { ...payload };
        if ('component' in given) given.component = given.component ? this.facade(given.component) : null;
        if (hook.cancelable) {
            given.cancelable = cancelable;
            given.preventDefault = () => {
                if (cancelable) prevented = true;
            };
        }
        for (const listener of this.listeners.get(name)) this.call(name, listener, target ?? payload.component ?? null, given);

        if (hook.event) {
            const { headers, ...detail } = payload;
            if ('component' in detail) detail.component = detail.component?.name ?? null;
            const place = hook.onDocument ? this.runtime.document : this.runtime.window;
            if (!place.dispatchEvent(new this.runtime.window.CustomEvent(hook.event, { detail, cancelable }))) prevented = true;
            /** Its earlier name: never cancelable, as it was not. */
            if (hook.was && (!hook.wasFor || hook.wasFor(payload))) place.dispatchEvent(new this.runtime.window.CustomEvent(hook.was, { detail }));
        }
        return !prevented;
    }

    /** After a component's render (runtime.afterRender): initialized the first time, then rendered. */
    rendered(component) {
        if (!this.started.has(component)) {
            this.started.add(component);
            this.announceTarget(HOOK.componentInitialized, component);
        }
        this.announceTarget(HOOK.componentRendered, component);
    }

    /** A store made in this tab: announced once the render that made it is over (its values then set re-render as any change). */
    madeStore(store) {
        queueMicrotask(() => this.announceTarget(HOOK.storeInitialized, store));
    }

    /** The headers a request is sent with, as beforeRequest's listeners leave them; null when one stopped it. */
    beforeRequest(component, method, kind, headers = {}) {
        return this.announce(HOOK.beforeRequest, { component, method, kind, headers }) ? headers : null;
    }

    afterRequest(component, method, kind, status, timing = {}) {
        this.announce(HOOK.afterRequest, { component, method, kind, status, cancelled: false, ...timing });
    }

    /** A hook whose listeners get the component or store itself, as plugins see it. */
    announceTarget(name, target) {
        for (const listener of this.listeners.get(name)) this.call(name, listener, target, this.facade(target));
    }

    /** A listener's failure is reported, as nitro:error; one of error's own only to the console (it would report itself). */
    call(name, listener, target, payload) {
        try {
            listener(payload);
        } catch (error) {
            if (name === HOOK.error) this.runtime.window.console.error('Nitro: an error hook failed:', error);
            else this.runtime.report(target, error);
        }
    }

    facade(target) {
        return this.runtime.plugins.facade(target);
    }
}
