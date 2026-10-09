// Viewi's PhpJsFunctions/Array/ArrayReplace.js, for Nitro's values.
// uses: _php_array_entries, _php_array
function array_replace(arr) { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-replace.php
  // Later arrays overwrite equal keys and add new ones; keys are never renumbered.
  const pairs = P.fns._php_array_entries(arr)
  for (let i = 1; i < arguments.length; i++) {
    for (const entry of P.fns._php_array_entries(arguments[i])) {
      pairs.push(entry)
    }
  }
  return P.fns._php_array(pairs)
}
