// Viewi's PhpJsFunctions/Math/IsFinite.js, for Fusion's values.
function is_finite(value) { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.is-finite.php
  return Number.isFinite(P.toFloat(value))
}
