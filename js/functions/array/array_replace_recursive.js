// Viewi's PhpJsFunctions/Array/ArrayReplaceRecursive.js (locutus), for Nitro's values.
// uses: _php_array
function array_replace_recursive(arr, ...replacements) {
    const replace = (base, replacement) => {
        const pairs = P.entries(base).map(([key, value]) => [key, value]);
        for (const [key, value] of P.entries(replacement)) {
            const at = pairs.findIndex((pair) => pair[0] === key);
            if (at === -1) {
                pairs.push([key, value]);
            } else if (P.isArray(pairs[at][1]) && P.isArray(value)) {
                pairs[at] = [key, replace(pairs[at][1], value)];
            } else {
                pairs[at] = [key, value];
            }
        }
        return P.fns._php_array(pairs);
    };
    return replacements.reduce(replace, arr);
}
