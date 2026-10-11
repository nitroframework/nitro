/**
 * What a #[Server] call asked for besides the new state: events dispatched ($this->dispatch()),
 * JavaScript to run ($this->js()), a reload of the page's properties, a redirect.
 */
import * as P from '../../php.js';
import { webUrl } from '../navigation/addresses.js';

export function applyEffects(runtime, component, effects) {
    for (const { event, params, to, self } of effects.dispatches ?? []) {
        runtime.dispatch(event, P.decode(params), component, { to, self });
    }
    for (const code of effects.js ?? []) {
        component.js(code);
    }
    if (effects.reload) runtime.reload(component, effects.reload.only).catch(() => {});
    if (effects.redirect) {
        const url = webUrl(effects.redirect.url, runtime.window.location.href);
        if (url) effects.redirect.navigate ? runtime.navigator.visit(url.href) : (runtime.window.location.href = url.href);
    }
}
