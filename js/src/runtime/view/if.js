/**
 * Viewi's renderIf (core/render/renderIf): a branch of an @if chain renders again only when
 * whether it is the one that shows changes; while it stays, its own bindings keep it current.
 * Nitro's @error is a branch too: its $message follows the messages.
 */
import { inPlace, removeBefore } from './anchor.js';
import { evaluate } from './call.js';
import { render } from './render.js';
import { createScope, dispose, propagate } from './scope.js';

/**
 * Whether the branch's condition holds, and the variables it renders with ({value, args, own}):
 * @error adds $message (own: its position, in arguments of its own).
 */
export function conditionOf(instance, directive, scope) {
    switch (directive.c) {
        case 'if':
        case 'else-if':
            return { value: !!evaluate(instance, directive.code, scope), args: scope.arguments };
        case 'error': {
            const field = evaluate(instance, directive.code, scope);
            const errors = instance.$this.$errors;
            if (!errors.has(field)) return { value: false, args: scope.arguments };
            const args = scope.arguments.slice();
            args[directive.message] = errors.first(field);
            return { value: true, args, own: [directive.message] };
        }
        default: // else, empty
            return { value: true, args: scope.arguments };
    }
}

export function renderIf(instance, node, scopeContainer, ifConditions, index) {
    const directive = node.i[0];
    let nextValue = true;
    for (let i = 0; i < index; i++) {
        nextValue = nextValue && !ifConditions.values[i];
    }
    const scope = scopeContainer.scope.parent;
    if (!scope || scope.disposed || !inPlace(scopeContainer.anchorNode)) {
        return;
    }
    let args = scope.arguments;
    let own = [];
    if (nextValue) {
        const condition = conditionOf(instance, directive, scope);
        nextValue = condition.value;
        args = condition.args;
        own = condition.own ?? [];
    }
    const anchorNode = scopeContainer.anchorNode;
    if (ifConditions.values[index] !== nextValue) {
        ifConditions.values[index] = nextValue;
        dispose(instance, scopeContainer.scope);
        if (nextValue) {
            // render
            const nextScope = createScope(scope, directive.c, args, own);
            scopeContainer.scope = nextScope;
            render(anchorNode, instance, node.h ?? [], nextScope, false, true);
        } else {
            // remove and dispose
            scopeContainer.scope = createScope(scope, directive.c);
            removeBefore(instance, anchorNode);
        }
    } else if (nextValue && args !== scopeContainer.scope.arguments) {
        /** Nitro: the branch stays; the variables it made of its own (@error's $message) follow, and the blocks in it that copied them. */
        const current = scopeContainer.scope.arguments;
        const changed = args.flatMap((value, position) => (Object.is(current[position], value) ? [] : [position]));
        Object.assign(current, args);
        if (changed.length) propagate(scopeContainer.scope, changed);
    }
}
