// Viewi's PhpJsFunctions/Array/ArrayUdiffAssoc.js, for Fusion's values.
// uses: _php_set_op
function array_udiff_assoc() { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-udiff-assoc.php
  const arrays = Array.prototype.slice.call(arguments)
  const valueCompare = arrays.pop()
  return P.fns._php_set_op(arrays, false, true, true, valueCompare, null)
}
