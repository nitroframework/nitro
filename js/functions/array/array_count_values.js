// Viewi's PhpJsFunctions/Array/ArrayCountValues.js (locutus), for Nitro's values.
// uses: _php_array
function array_count_values(array) {
    const counts = new Map();
    for (const [, value] of P.entries(array)) {
        /** Only strings and ints are counted (PHP warns about the others), as keys PHP makes of them. */
        if (typeof value !== 'string' && !Number.isInteger(value)) continue;
        const key = P.key(value);
        counts.set(key, (counts.get(key) ?? 0) + 1);
    }
    return P.fns._php_array([...counts]);
}
