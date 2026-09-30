/**
 * fusion:loading: what shows while a component waits for the server.
 *
 *   fusion:loading                   shown only while loading
 *   fusion:loading.remove            hidden while loading
 *   fusion:loading.class="opacity-50" classes added while loading (.class.remove: removed)
 *   fusion:loading.attr="disabled"   attribute set while loading
 *   fusion:target="save,delete"      only for calls to these methods
 */
export const STYLE = '[fusion\\:loading]:not([data-fusion-loading]){display:none!important}'
    + '[fusion\\:loading\\.remove][data-fusion-loading]{display:none!important}';

export function applyLoading(component, active) {
    const methods = active;
    for (const el of component.scan('*')) {
        if (component.runtime.owner(el) !== component) continue;
        const attributes = el.getAttributeNames().filter((n) => n === 'fusion:loading' || n.startsWith('fusion:loading.'));
        if (attributes.length === 0) continue;

        const target = el.getAttribute('fusion:target');
        const on = methods.size > 0 && (!target || target.split(',').map((t) => t.trim()).some((t) => methods.has(t)));

        on ? el.setAttribute('data-fusion-loading', '') : el.removeAttribute('data-fusion-loading');

        for (const attribute of attributes) {
            const modifiers = attribute.split('.').slice(1);
            const value = el.getAttribute(attribute) ?? '';
            if (modifiers[0] === 'class') {
                const classes = value.split(/\s+/).filter(Boolean);
                const add = modifiers[1] === 'remove' ? !on : on;
                classes.forEach((c) => el.classList.toggle(c, add));
            } else if (modifiers[0] === 'attr') {
                on ? el.setAttribute(value, '') : el.removeAttribute(value);
            }
        }
    }
}
