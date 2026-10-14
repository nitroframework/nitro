/**
 * Viewi's renderDynamic (core/render/renderDynamic): what is between the anchors is disposed,
 * removed and rendered again. Nitro renders its @for, @while and @switch so, and a loop a
 * @break ends; a @switch only when its case changes (choose).
 *
 * @param {Function} content  (target, scope, hydrate, insert) => void: renders the block
 * @param {Function|null} choose  (scope) => what the content depends on; the same, nothing renders
 */
import { createAnchorNode, getAnchor, inPlace, removeBefore } from './anchor.js';
import { createScope, dispose, track } from './scope.js';

export function renderDynamic(target, instance, scope, hydrate, insert, codes, content, choose = null) {
    const anchor = hydrate ? getAnchor(instance, target) : undefined;
    const anchorBegin = createAnchorNode(instance, target, insert, anchor); // begin dynamic
    const scopeContainer = { scope: around(createScope(scope, 'dynamic', scope.arguments.slice()), scope), chosen: choose?.(scope) };
    content(target, scopeContainer.scope, hydrate, insert);
    const anchorNode = createAnchorNode(instance, target, insert, anchor, anchorBegin._anchor); // end dynamic

    track(instance, codes, scope, () => {
        if (scope.disposed || !inPlace(anchorNode)) {
            return;
        }
        if (choose) {
            const chosen = choose(scope);
            if (chosen === scopeContainer.chosen) return;
            scopeContainer.chosen = chosen;
        }
        dispose(instance, scopeContainer.scope);
        removeBefore(instance, anchorNode);
        scopeContainer.scope = around(createScope(scope, 'dynamic', scope.arguments.slice()), scope);
        content(anchorNode, scopeContainer.scope, false, true);
    });
}

/** The block's scope, with its own copy of the variables: what a loop in it leaves set is the scope's around it (foreach.js leaveLoop()). */
function around(region, scope) {
    region.around = scope;
    return region;
}
