// Viewi's PhpJsFunctions/Array/ArrayUintersect.js, for Fusion's values.
// uses: _php_set_op
function array_uintersect() { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-uintersect.php
  const arrays = Array.prototype.slice.call(arguments)
  const valueCompare = arrays.pop()
  return P.fns._php_set_op(arrays, true, true, false, valueCompare, null)
}
