/**
 * What the Vue, React and Solid adapters share: everything about a Fusion component that is not
 * a framework's own reactivity. An adapter registers its engine here, and binds the component
 * that connect() describes to its framework; the behaviour is the same in all three, because it
 * is written once, here.
 *
 * A component, as connect() gives it:
 *
 *   read()            a plain snapshot: the properties, the #[Computed] values, `errors` (the
 *                     validation messages by field) and `unsaved` (the properties changed here
 *                     that the server has not seen yet), all by their PHP names
 *   functions         the methods (a browser one runs here; a #[Server] one returns a promise of
 *                     its value), `set(path, value)` and `dispatch(event, ...params)`
 *   subscribe(fn)     fn(names) when something changed; returns the unsubscribe
 *   changed(names)    told by the engine's update(): what the component changed
 *
 * A #[Server] method carries its call's status, as Blade's fusion:loading shows it:
 *
 *   save.processing   the call is running        (fusion:loading)
 *   save.slow         ...and has taken 200ms     (fusion:loading.delay)
 *   save.failed       the call itself failed (the server's error, no connection)
 *   save.error        why, as a message
 *
 * Rules that fail are not a failed call: the method answers, and `errors` has the messages.
 *
 * The adapters stay small: they turn these into Vue refs, a React store, a Solid store or Svelte
 * $state, each through its framework's public API alone, so a framework's release asks nothing
 * of Fusion beyond running the parity tests against it. The
 * same binding renders the view on the server (adapters/server): there the handle is made of
 * the state the server sent, so the first HTML is what the browser's first render gives.
 */

/** How long a call runs before it is `slow`, as fusion:loading.delay waits. */
const SLOW_AFTER = 200;

/**
 * Register an adapter's engine with Fusion: now, or once Fusion is starting (a script that runs
 * before the runtime). `views` is what import.meta.glob() gives: loaders, or modules with
 * { eager: true }.
 *
 * @param {string} name           the engine's name, as #[View(vue: ...)] names it
 * @param {object} views          path => loader, or path => module
 * @param {string[]} extensions   the view files' extensions
 * @param {Function} mount        (view, el, handle, { hydrate }) => { update(names), unmount() }:
 *                                hydrate when el holds the view's server-rendered HTML
 */
export function register(name, views, extensions, mount) {
    const engine = {
        load: (view) => findView(views, view, extensions, name),
        mount: (view, el, handle, options = {}) => mount(view, el, handle, { hydrate: Boolean(options.hydrate) }),
    };

    if (window.Fusion?.engine) window.Fusion.engine(name, engine);
    else document.addEventListener('fusion:starting', ({ detail }) => detail.Fusion.engine(name, engine), { once: true });
}

/** A view's module, by its name ('cart/badge' is .../cart/badge.vue): in the browser, and in the render service. */
export function findView(views, view, extensions, engine) {
    const path = Object.keys(views).find((key) => {
        const match = /^(.*)\.(\w+)$/.exec(key);
        return match !== null && extensions.includes(match[2]) && (match[1].endsWith(`/${view}`) || match[1] === view);
    });

    if (path === undefined) {
        return Promise.reject(new Error(`Fusion: no ${engine} view [${view}] among those the adapter was given (${extensions.map((e) => `.${e}`).join(', ')})`));
    }

    const entry = views[path];
    return Promise.resolve(typeof entry === 'function' ? entry() : entry).then((module) => module.default ?? module);
}

/**
 * A component, for an adapter to bind to its framework.
 *
 * @param {object} handle   the engine's handle (runtime/view/script.js)
 * @param {object} options  status(initial): the framework's reactive object for a call's status
 *                          (Vue's reactive(), Solid's createMutable()); a plain object by default
 */
export function connect(handle, { status = (initial) => initial } = {}) {
    const listeners = new Set();
    const notify = (names) => listeners.forEach((listener) => listener(names));

    const read = () => ({
        ...Object.fromEntries(handle.properties.map((name) => [name, handle.get(name)])),
        ...Object.fromEntries(handle.computed.map((name) => [name, handle.get(name)])),
        errors: handle.errors(),
        unsaved: handle.unsaved(),
    });

    const functions = {};

    for (const name of handle.methods.filter((method) => !handle.computed.includes(method))) {
        functions[name] = (...args) => handle.call(name, ...args);
    }

    for (const name of handle.server) {
        functions[name] = serverCall(name, handle, status, notify);
    }

    /** Written at once, and told at once: a controlled input keeps its caret. */
    functions.set = (path, value) => {
        handle.set(path, value);
        notify([String(path).split('.')[0]]);
    };

    functions.dispatch = (event, ...params) => handle.dispatch(event, ...params);

    return {
        read,
        functions,
        subscribe(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        changed: notify,
    };
}

/** A #[Server] method, with its call's status on it. */
function serverCall(name, handle, status, notify) {
    const state = status({ processing: false, slow: false, failed: false, error: null });
    let timer = null;

    const settle = (changes) => {
        clearTimeout(timer);
        Object.assign(state, { processing: false, slow: false, ...changes });
        notify(['$status']);
    };

    const call = (...args) => {
        Object.assign(state, { processing: true, slow: false, failed: false, error: null });
        timer = setTimeout(() => {
            state.slow = true;
            notify(['$status']);
        }, SLOW_AFTER);
        notify(['$status']);

        return handle.call(name, ...args).then(
            (value) => {
                settle({});
                return value;
            },
            (error) => {
                settle({ failed: true, error: String(error?.message ?? error) });
                throw error;
            },
        );
    };

    for (const key of ['processing', 'slow', 'failed', 'error']) {
        Object.defineProperty(call, key, { get: () => state[key], enumerable: true });
    }

    return call;
}
