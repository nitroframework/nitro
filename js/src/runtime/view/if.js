/**
 * Viewi's renderIf (core/render/renderIf): a branch of an @if chain renders again only when
 * whether it is the one that shows changes; while it stays, its own bindings keep it current.
 * Nitro's @error is a branch too: its $message follows the messages.
 */
import { removeBefore } from './anchor.js';
import { evaluate } from './call.js';
import { render } from './render.js';
import { createScope, dispose } from './scope.js';

/**
 * Whether the branch's condition holds, and the variables it renders with ({value, args}):
 * @error adds $message.
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
            return { value: true, args };
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
    if (!scope || scope.disposed) {
        return;
    }
    let args = scope.arguments;
    if (nextValue) {
        const condition = conditionOf(instance, directive, scope);
        nextValue = condition.value;
        args = condition.args;
    }
    const anchorNode = scopeContainer.anchorNode;
    if (ifConditions.values[index] !== nextValue) {
        ifConditions.values[index] = nextValue;
        dispose(instance, scopeContainer.scope);
        if (nextValue) {
            // render
            const nextScope = createScope(scope, directive.c, args);
            scopeContainer.scope = nextScope;
            render(anchorNode, instance, node.h ?? [], nextScope, false, true);
        } else {
            // remove and dispose
            scopeContainer.scope = createScope(scope, directive.c);
            removeBefore(instance, anchorNode);
        }
    } else if (nextValue && args !== scopeContainer.scope.arguments) {
        /** Nitro: the branch stays; the variables it made of its own (@error's $message) follow. */
        Object.assign(scopeContainer.scope.arguments, args);
    }
}
