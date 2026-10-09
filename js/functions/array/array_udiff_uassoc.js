// Viewi's PhpJsFunctions/Array/ArrayUdiffUassoc.js, for Nitro's values.
// uses: _php_set_op
function array_udiff_uassoc() { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-udiff-uassoc.php
  const arrays = Array.prototype.slice.call(arguments)
  const keyCompare = arrays.pop()
  const valueCompare = arrays.pop()
  return P.fns._php_set_op(arrays, false, true, true, valueCompare, keyCompare)
}
