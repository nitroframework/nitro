// Viewi's PhpJsFunctions/Helpers/PhpArray.js: a PHP array from [key, value] pairs, the way PHP
// builds one: keys normalised, a repeated key keeps its first position and takes the last value,
// a list when the keys are 0..n-1 in order. Fusion: a list or a Map (not an object).
function _php_array(pairs) {
    const map = new Map();
    for (const pair of pairs) {
        map.set(P.key(pair[0]), pair[1]);
    }
    return P.normalize(map);
}
