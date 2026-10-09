/**
 * Viewi's renderDynamic (core/render/renderDynamic): what is between the anchors is disposed,
 * removed and rendered again. Nitro renders its @for, @while and @switch so, and a loop a
 * @break ends; a @switch only when its case changes (choose).
 *
 * @param {Function} content  (target, scope, hydrate, insert) => void: renders the block
 * @param {Function|null} choose  (scope) => what the content depends on; the same, nothing renders
 */
import { createAnchorNode, getAnchor, removeBefore } from './anchor.js';
import { createScope, dispose, track } from './scope.js';

export function renderDynamic(target, instance, scope, hydrate, insert, codes, content, choose = null) {
    const anchor = hydrate ? getAnchor(instance, target) : undefined;
    const anchorBegin = createAnchorNode(instance, target, insert, anchor); // begin dynamic
    const scopeContainer = { scope: createScope(scope, 'dynamic', scope.arguments.slice()), chosen: choose?.(scope) };
    content(target, scopeContainer.scope, hydrate, insert);
    const anchorNode = createAnchorNode(instance, target, insert, anchor, anchorBegin._anchor); // end dynamic

    track(instance, codes, scope, () => {
        if (scope.disposed) {
            return;
        }
        if (choose) {
            const chosen = choose(scope);
            if (chosen === scopeContainer.chosen) return;
            scopeContainer.chosen = chosen;
        }
        dispose(instance, scopeContainer.scope);
        removeBefore(instance, anchorNode);
        scopeContainer.scope = createScope(scope, 'dynamic', scope.arguments.slice());
        content(anchorNode, scopeContainer.scope, false, true);
    });
}
