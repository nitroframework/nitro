// Viewi's PhpJsFunctions/Array/ArrayUintersectUassoc.js, for Fusion's values.
// uses: _php_set_op
function array_uintersect_uassoc() { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-uintersect-uassoc.php
  const arrays = Array.prototype.slice.call(arguments)
  const keyCompare = arrays.pop()
  const valueCompare = arrays.pop()
  return P.fns._php_set_op(arrays, true, true, true, valueCompare, keyCompare)
}
