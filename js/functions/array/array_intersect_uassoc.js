// Viewi's PhpJsFunctions/Array/ArrayIntersectUassoc.js, for Nitro's values.
// uses: _php_set_op
function array_intersect_uassoc() { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-intersect-uassoc.php
  const arrays = Array.prototype.slice.call(arguments)
  const keyCompare = arrays.pop()
  return P.fns._php_set_op(arrays, true, true, true, null, keyCompare)
}
