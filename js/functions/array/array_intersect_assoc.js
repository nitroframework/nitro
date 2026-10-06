// Viewi's PhpJsFunctions/Array/ArrayIntersectAssoc.js, for Fusion's values.
// uses: _php_set_op
function array_intersect_assoc() { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.array-intersect-assoc.php
  const arrays = Array.prototype.slice.call(arguments)
  return P.fns._php_set_op(arrays, true, true, true, null, null)
}
