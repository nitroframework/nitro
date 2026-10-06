/**
 * fusion:dirty: what shows while the component has changes the server has not seen (since the
 * state it last signed: a #[Server] call, a reload).
 *
 *   fusion:dirty                     shown only while there are changes
 *   fusion:dirty.remove              hidden while there are changes
 *   fusion:dirty.class="unsaved"     classes added while there are changes (.class.remove: removed)
 *   fusion:dirty.attr="data-unsaved" attribute set while there are changes
 *   fusion:target="title,form.email" only for changes to these properties (or paths)
 */
export const STYLE = '[fusion\\:dirty]:not([data-fusion-dirty]){display:none!important}'
    + '[fusion\\:dirty\\.remove][data-fusion-dirty]{display:none!important}';

export function applyDirty(component) {
    for (const el of component.scan('*')) {
        if (component.runtime.owner(el) !== component) continue;
        const attributes = el.getAttributeNames().filter((n) => n === 'fusion:dirty' || n.startsWith('fusion:dirty.'));
        if (attributes.length === 0) continue;

        const target = el.getAttribute('fusion:target');
        const paths = target ? target.split(',').map((t) => t.trim()).filter(Boolean) : null;
        const on = component.unsaved(paths).length > 0;

        on ? el.setAttribute('data-fusion-dirty', '') : el.removeAttribute('data-fusion-dirty');

        for (const attribute of attributes) {
            const form = attribute.split('.').slice(1);
            const value = el.getAttribute(attribute) ?? '';
            if (form[0] === 'class') {
                const add = form[1] === 'remove' ? !on : on;
                value.split(/\s+/).filter(Boolean).forEach((c) => el.classList.toggle(c, add));
            } else if (form[0] === 'attr') {
                on ? el.setAttribute(value, '') : el.removeAttribute(value);
            }
        }
    }
}
