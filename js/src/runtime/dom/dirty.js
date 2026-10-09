/**
 * nitro:dirty: what shows while the component has changes the server has not seen (since the
 * state it last signed: a #[Server] call, a reload).
 *
 *   nitro:dirty                     shown only while there are changes
 *   nitro:dirty.remove              hidden while there are changes
 *   nitro:dirty.class="unsaved"     classes added while there are changes (.class.remove: removed)
 *   nitro:dirty.attr="data-unsaved" attribute set while there are changes
 *   nitro:target="title,form.email" only for changes to these properties (or paths)
 */
export const STYLE = '[nitro\\:dirty]:not([data-nitro-dirty]){display:none!important}'
    + '[nitro\\:dirty\\.remove][data-nitro-dirty]{display:none!important}';

export function applyDirty(component) {
    for (const el of component.scan('*')) {
        if (component.runtime.owner(el) !== component) continue;
        const attributes = el.getAttributeNames().filter((n) => n === 'nitro:dirty' || n.startsWith('nitro:dirty.'));
        if (attributes.length === 0) continue;

        const target = el.getAttribute('nitro:target');
        const paths = target ? target.split(',').map((t) => t.trim()).filter(Boolean) : null;
        const on = component.unsaved(paths).length > 0;

        on ? el.setAttribute('data-nitro-dirty', '') : el.removeAttribute('data-nitro-dirty');

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
