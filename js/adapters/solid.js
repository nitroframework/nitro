/**
 * Solid views for Fusion components: #[View(solid: 'post-editor')] on the PHP class, and
 * resources/views/solid/post-editor.jsx (or .tsx) as its view. Your Vite builds them (with
 * vite-plugin-solid); register them once in your JavaScript:
 *
 *     import { fusionSolid } from 'nitro-fusion/solid';
 *
 *     fusionSolid(import.meta.glob('../views/solid/**\/*.{jsx,tsx}'));
 *
 * The view is a Solid component, and gets the Fusion component as its props, by the PHP's names.
 * As with any Solid props, read them where they are used (props.count), not destructured:
 *
 *     export default function PostEditor(props) {
 *         return <>
 *             <input value={props.title} onInput={(e) => props.set('title', e.target.value)} />
 *             <button onClick={() => props.increment(3)}>{props.count} / {props.doubled}</button>
 *             <button onClick={props.save} disabled={props.save.processing}>Save</button>
 *             {props.errors.title?.[0]}
 *         </>;
 *     }
 *
 * Properties and #[Computed] values are plain data, kept in a Solid store that changes only where
 * the component's state did; methods are functions (a browser one runs here, compiled from the
 * PHP; a #[Server] one returns a promise of its value and carries processing, failed and error);
 * set(path, value) writes a property or a key under it ("form.email"); errors holds the validation
 * messages by field; dispatch(event, ...params) sends an event. useFusion() gives the same to any
 * component inside the view.
 */
import { createComponent, createContext, mergeProps, useContext } from 'solid-js';
import { createStore, reconcile } from 'solid-js/store';
import { render } from 'solid-js/web';

const FusionContext = createContext(null);

/**
 * Register the Solid engine with Fusion: `views` is what import.meta.glob() gives (loaders, or
 * modules with { eager: true }).
 */
export function fusionSolid(views) {
    const register = (Fusion) => Fusion.engine('solid', engine(views));
    if (window.Fusion?.engine) register(window.Fusion);
    else document.addEventListener('fusion:starting', ({ detail }) => register(detail.Fusion), { once: true });
}

/** Inside a Fusion component's Solid view: the component, as its view gets it in its props. */
export function useFusion() {
    const fusion = useContext(FusionContext);
    if (!fusion) throw new Error('Fusion: useFusion() is for the view of a Fusion component (#[View(solid: ...)])');
    return fusion;
}

function engine(views) {
    return {
        load(name) {
            const key = Object.keys(views).find((path) => /\.(jsx|tsx|js|ts)$/.test(path) && (path.replace(/\.\w+$/, '').endsWith(`/${name}`) || path.replace(/\.\w+$/, '') === name));
            if (key === undefined) throw new Error(`Fusion: no Solid view [${name}] among those fusionSolid() was given`);
            const entry = views[key];
            return Promise.resolve(typeof entry === 'function' ? entry() : entry).then((module) => module.default ?? module);
        },

        mount(View, el, handle) {
            const fusion = state(handle);
            const dispose = render(
                () => createComponent(FusionContext.Provider, {
                    value: fusion.props,
                    get children() {
                        return createComponent(View, fusion.props);
                    },
                }),
                el,
            );

            return { update: (names) => fusion.update(names), unmount: dispose };
        },
    };
}

/** The component as Solid reads it: a store of its values, and its functions. */
function state(handle) {
    const read = () => ({
        ...Object.fromEntries(handle.properties.map((name) => [name, handle.get(name)])),
        ...Object.fromEntries(handle.computed.map((name) => [name, handle.get(name)])),
        errors: handle.errors(),
    });
    const [values, setValues] = createStore(read());

    const functions = {};
    for (const name of handle.methods.filter((method) => !handle.computed.includes(method))) {
        functions[name] = (...args) => handle.call(name, ...args);
    }
    for (const name of handle.server) {
        const [status, setStatus] = createStore({ processing: false, failed: false, error: null });
        const call = (...args) => {
            setStatus({ processing: true, failed: false, error: null });
            return handle.call(name, ...args).then(
                (value) => {
                    setStatus('processing', false);
                    return value;
                },
                (error) => {
                    setStatus({ processing: false, failed: true, error: String(error?.message ?? error) });
                    throw error;
                },
            );
        };
        for (const key of ['processing', 'failed', 'error']) Object.defineProperty(call, key, { get: () => status[key] });
        functions[name] = call;
    }

    /** set(path, value): written at once, so an input keeps its caret. */
    functions.set = (path, value) => {
        handle.set(path, value);
        const root = String(path).split('.')[0];
        setValues(root, reconcile(handle.get(root)));
    };
    functions.dispatch = (event, ...params) => handle.dispatch(event, ...params);

    return {
        props: mergeProps(values, functions),
        /** What changed: those values again, and the derived ones; the store changes only where they differ. */
        update(names) {
            const fresh = read();
            for (const name of names.includes('*') ? Object.keys(fresh) : [...names, ...handle.computed, 'errors']) {
                if (name in fresh) setValues(name, reconcile(fresh[name]));
            }
        },
    };
}
