// Viewi's PhpJsFunctions/Array/ArrayDiffUassoc.js, for Nitro's values.
// uses: _php_set_op
function array_diff_uassoc() { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-diff-uassoc.php
  const arrays = Array.prototype.slice.call(arguments)
  const keyCompare = arrays.pop()
  return P.fns._php_set_op(arrays, false, true, true, null, keyCompare)
}
