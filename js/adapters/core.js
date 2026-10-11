/**
 * What the Vue, React, Solid and Svelte adapters share: everything about a Nitro component that
 * is not a framework's own reactivity. An adapter registers its engine here, and binds the
 * component that connect() describes to its framework; the behaviour is the same in all of them,
 * because it is written once, here.
 *
 * A component, as connect() gives it:
 *
 *   read()            a plain snapshot: the properties, the #[Computed] values, `errors` (the
 *                     validation messages by field), `unsaved` (the properties changed here
 *                     that the server has not seen yet) and `uploads` (each property's upload:
 *                     uploading, progress 0-100, error), all by their PHP names
 *   functions         the methods (a browser one runs here; a #[Server] one returns a promise of
 *                     its value), `set(path, value)`, `dispatch(event, ...params)`,
 *                     `upload(path, files)` (files into an Upload or #[Uploads] property, as
 *                     nitro:model does for a file input: a promise of its new value),
 *                     `link(href, ...modifiers)` (a nitro:navigate link's attributes, to spread)
 *                     and `visit(url)`
 *   subscribe(fn)     fn(names) when something changed; returns the unsubscribe
 *   changed(names)    told by the engine's update(): what the component changed
 *
 * A #[Server] method carries its call's status, as Blade's nitro:loading shows it:
 *
 *   save.processing   the call is running        (nitro:loading)
 *   save.slow         ...and has taken 200ms     (nitro:loading.delay)
 *   save.failed       the call itself failed (the server's error, no connection)
 *   save.error        why, as a message
 *
 * Rules that fail are not a failed call: the method answers, and `errors` has the messages.
 *
 * The adapters stay small: they turn these into Vue refs, a React store, a Solid store or Svelte
 * $state, each through its framework's public API alone, so a framework's release asks nothing
 * of Nitro beyond running the parity tests against it. The
 * same binding renders the view on the server (adapters/server): there the handle is made of
 * the state the server sent, so the first HTML is what the browser's first render gives.
 */

/** What this core gives every view besides the component's own names: Nitro's build refuses a property or method named as one. */
import reserved from './names.json' with { type: 'json' };
import protocol from '../protocol.json' with { type: 'json' };

/** What a view is told changed besides properties ($errors, $unsaved, $uploads, $status): js/protocol.json's. */
const SIGNALS = protocol.signals;

/** How long a call runs before it is `slow`, as nitro:loading.delay waits. */
const SLOW_AFTER = 200;

/**
 * Register an adapter's engine with Nitro: now, or once Nitro is starting (a script that runs
 * before the runtime). `views` is what import.meta.glob() gives: loaders, or modules with
 * { eager: true }.
 *
 * @param {string} name           the engine's name, as #[View(vue: ...)] names it
 * @param {object} views          path => loader, or path => module
 * @param {Function} mount        (view, el, handle, { hydrate }) => { update(names), unmount() }:
 *                                hydrate when el holds the view's server-rendered HTML
 */
export function register(name, views, mount) {
    const engine = {
        load: (view) => findView(views, view, name),
        mount: (view, el, handle, options = {}) => mount(view, el, handle, { hydrate: Boolean(options.hydrate) }),
    };

    if (window.Nitro?.engine) window.Nitro.engine(name, engine);
    else document.addEventListener('nitro:starting', ({ detail }) => detail.Nitro.engine(name, engine), { once: true });
}

/** What ends a namespace in a view's name: 'issues::list'. */
const SEPARATOR = protocol.view.namespaceSeparator;

/**
 * A namespace's views (a module's, a package's), for an adapter: its import.meta.glob(), keyed
 * so that #[View(vue: 'issues::list')] finds list.vue among them and only them.
 *
 *     nitroVue({ ...import.meta.glob('../views/vue/**\/*.vue'),
 *                ...namespaced('issues', import.meta.glob('../../modules/Issues/resources/views/vue/**\/*.vue')) });
 */
export function namespaced(namespace, views) {
    return Object.fromEntries(Object.entries(views).map(([path, view]) => [namespace + SEPARATOR + path, view]));
}

/**
 * A view's module, by its name ('cart/badge' is .../cart/badge.vue; 'issues::list' is list.vue
 * among the views namespaced() keyed for 'issues'): in the browser, and in the render service.
 * By its name alone: which files are views (their extensions) is the app's import.meta.glob()'s
 * choice.
 */
export function findView(views, view, engine) {
    const at = view.indexOf(SEPARATOR);
    const prefix = at === -1 ? '' : view.slice(0, at + SEPARATOR.length);
    const name = view.slice(prefix.length);

    const path = Object.keys(views).find((key) => {
        if (prefix === '' ? key.includes(SEPARATOR) : !key.startsWith(prefix)) return false;
        const match = /^(.*)\.(\w+)$/.exec(key.slice(prefix.length));
        return match !== null && (match[1].endsWith(`/${name}`) || match[1] === name);
    });

    if (path === undefined) {
        return Promise.reject(new Error(`Nitro: no ${engine} view [${view}] among those the adapter was given (its import.meta.glob())`));
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
    /** Each property's upload: { uploading, progress, error }, for the view to show. */
    const uploads = {};

    const read = () => ({
        ...Object.fromEntries(handle.properties.map((name) => [name, handle.get(name)])),
        ...Object.fromEntries(handle.computed.map((name) => [name, handle.get(name)])),
        errors: handle.errors(),
        unsaved: handle.unsaved(),
        uploads: { ...uploads },
    });

    const functions = {};

    for (const name of handle.methods.filter((method) => !handle.computed.includes(method))) {
        functions[name] = (...args) => handle.call(name, ...withoutEvents(args));
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

    /**
     * A nitro:navigate link's attributes, for the view to spread on an <a>: link('/posts'),
     * link('/reports', 'keypress'), and nitro:prefetch with it: link('/reports', 'prefetch.visible').
     * A framework's own syntax can't always write the attribute (JSX and Svelte take no dots in a
     * name; React drops one with no value).
     */
    functions.link = (href, ...modifiers) => {
        const prefetch = modifiers.find((m) => m === 'prefetch' || m.startsWith('prefetch.'));
        const navigate = modifiers.filter((m) => m !== prefetch);
        return { href, [['nitro:navigate', ...navigate].join('.')]: '', ...(prefetch ? { [`nitro:${prefetch}`]: '' } : {}) };
    };

    /** A nitro:navigate visit, as $visit() is in a Blade handler. */
    functions.visit = (url) => globalThis.Nitro.visit(url);

    /**
     * Files (a FileList, an array) into an Upload or #[Uploads] property: uploads[path] follows it.
     * Resolves with the property's new value, or null when the server's rules refused them
     * (`errors` says why); rejects when the request failed.
     */
    functions.upload = (path, files) => {
        const tell = (state) => {
            uploads[path] = { ...uploads[path], ...state };
            notify([SIGNALS.uploads]);
        };
        tell({ uploading: true, progress: 0, error: null });

        return handle.upload(path, files, (progress) => tell({ progress })).then(
            (value) => {
                tell({ uploading: false, progress: value === null ? uploads[path].progress : 100 });
                return value;
            },
            (error) => {
                tell({ uploading: false, error: String(error?.message ?? error) });
                throw error;
            },
        );
    };

    /** names.json and this core are one list: a name it lists and the core does not give is a mistake here. */
    const given = read();
    for (const name of reserved.functions) if (typeof functions[name] !== 'function') throw new Error(`Nitro: names.json lists ${name}(), which the core does not give`);
    for (const name of reserved.values) if (!(name in given)) throw new Error(`Nitro: names.json lists ${name}, which the core does not give`);

    return {
        read,
        uploads: () => ({ ...uploads }),
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
        notify([SIGNALS.status]);
    };

    const call = (...args) => {
        Object.assign(state, { processing: true, slow: false, failed: false, error: null });
        timer = setTimeout(() => {
            state.slow = true;
            notify([SIGNALS.status]);
        }, SLOW_AFTER);
        notify([SIGNALS.status]);

        return handle.call(name, ...withoutEvents(args)).then(
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

/**
 * A method's arguments without the event a handler passes on its own: @click="save" in Vue,
 * onClick={save} in React, onclick={save} in Svelte call save(event). PHP can take no event, so
 * the call is the method's, as if written save().
 */
function withoutEvents(args) {
    const isEvent = (arg) => (typeof Event !== 'undefined' && arg instanceof Event) || (arg !== null && typeof arg === 'object' && typeof Event !== 'undefined' && arg.nativeEvent instanceof Event);
    return args.filter((arg) => !isEvent(arg));
}
