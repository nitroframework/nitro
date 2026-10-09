/**
 * Viewi calls an expression with the component and the scope's arguments (callArguments). Nitro's
 * expressions take the component's context ($this) first, then the template's variables.
 */
export function callArguments(instance, scope) {
    return [instance.$this, ...scope.arguments];
}

export function evaluate(instance, code, scope) {
    return instance.$$t[code].apply(null, callArguments(instance, scope));
}
