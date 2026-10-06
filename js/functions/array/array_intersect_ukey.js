// Viewi's PhpJsFunctions/Array/ArrayIntersectUkey.js, for Fusion's values.
// uses: _php_set_op
function array_intersect_ukey() { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-intersect-ukey.php
  const arrays = Array.prototype.slice.call(arguments)
  const keyCompare = arrays.pop()
  return P.fns._php_set_op(arrays, true, false, true, null, keyCompare)
}
