// Viewi's PhpJsFunctions/Array/ArrayChangeKeyCase.js (locutus), for Nitro's values.
// uses: _php_array, _ascii_lower
function array_change_key_case(array, cs = 0) {
    /**
     * Nitro: a list's keys are ints (it comes back as it is); a Map's string keys change case,
     * ASCII letters only, and a key made the same as an earlier one takes its place (CASE_LOWER
     * is 0, CASE_UPPER 1, as PHP's constants): FunctionParityTest.
     */
    if (Array.isArray(array)) {
        return array;
    }
    const upper = P.toInt(cs) !== 0;
    return P.fns._php_array(P.entries(array).map(([key, value]) => [
        typeof key !== 'string' ? key : upper ? key.replace(/[a-z]/g, (c) => c.toUpperCase()) : P.fns._ascii_lower(key),
        value,
    ]));
}
