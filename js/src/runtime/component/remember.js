/**
 * #[Remember]: the remembered properties of the components on the page, kept in the history entry
 * (navigate.js saves them), and put back when the entry is shown again (a reload, back, forward).
 * A component is known by its name and its place among those of that name.
 */
import * as P from '../../php.js';

/** The remembered properties of the components on the page, by component; null when there are none. */
export function remembered(runtime) {
    const saved = {};
    for (const [key, component] of rememberKeys(runtime)) {
        const values = {};
        for (const [name, prop] of Object.entries(component.definition.props)) {
            if (prop.remember) values[name] = P.encode(component.data.get(name) ?? null);
        }
        if (Object.keys(values).length > 0) saved[key] = values;
    }
    return Object.keys(saved).length > 0 ? saved : null;
}

/** The values the history entry kept, back in the components they belong to. */
export function restoreRemembered(runtime, saved) {
    if (!saved || typeof saved !== 'object') return;
    for (const [key, component] of rememberKeys(runtime)) {
        for (const [name, value] of Object.entries(saved[key] ?? {})) {
            const prop = component.definition.props[name];
            if (prop?.remember) component.write(name, P.decode(value), prop);
        }
    }
}

/** @returns {Array<[string, Component]>} each component on the page with its key: "name#n" */
function rememberKeys(runtime) {
    const seen = new Map();
    return runtime.components().filter((component) => !component.destroyed).map((component) => {
        const n = seen.get(component.name) ?? 0;
        seen.set(component.name, n + 1);
        return [`${component.name}#${n}`, component];
    });
}
