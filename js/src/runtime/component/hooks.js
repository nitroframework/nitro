/**
 * A component's hooks in the browser, as its definition lists them. The compiler writes them
 * from Support/ComponentHooks.php and Support/PropertyHooks.php, so the runtime never builds
 * a hook's name:
 *
 *   definition.hooks           mounted, rendered, destroyed => the method that is it
 *   definition.propertyHooks   a property ('*' for every one) => updating, updated => the method
 *
 * A method in definition.server is #[Server]: calling it is a request, with the changes made
 * here sent along, as for any #[Server] method.
 */

/** mounted(), rendered(), destroyed(), when the component has it. */
export function componentHook(component, name) {
    const method = component.definition.hooks?.[name];
    if (method === undefined) return;
    try {
        run(component, method, [], name === 'destroyed');
    } catch (error) {
        component.runtime.report(component, error);
    }
}

/**
 * The property hooks of a change the view makes: when is 'updating' (before it is made) or
 * 'updated' (after). The property's own take its value and the key under it (null for the
 * property itself); the '*' ones the change's path and its value.
 */
export function propertyHooks(component, when, root, keys, value) {
    const hooks = component.definition.propertyHooks ?? {};
    const own = hooks[root]?.[when];
    const any = hooks['*']?.[when];
    if (own !== undefined) run(component, own, [value, keys.length ? keys.join('.') : null]);
    if (any !== undefined) run(component, any, [[root, ...keys].join('.'), value]);
}

/** leaving: destroyed(), whose #[Server] call goes though the component has left. */
function run(component, method, args, leaving = false) {
    if (component.definition.server.includes(method)) {
        component.runtime.network.call(component, method, args, { leaving }).catch(() => {});
        return;
    }
    component.ctx[method](...args);
}
