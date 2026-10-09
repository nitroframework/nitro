// Viewi's PhpJsFunctions/Array/ArrayDiffAssoc.js, for Nitro's values.
// uses: _php_set_op
function array_diff_assoc() { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-diff-assoc.php
  const arrays = Array.prototype.slice.call(arguments)
  return P.fns._php_set_op(arrays, false, true, true, null, null)
}
