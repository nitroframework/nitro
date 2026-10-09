// Viewi's PhpJsFunctions/Math/Decbin.js, for Nitro's values.
function decbin(number) {
  //  discuss at: https://www.php.net/manual/en/function.decbin.php
  // The 64-bit unsigned value, as PHP: decbin(-1) is all ones.
  return BigInt.asUintN(64, BigInt(P.toInt(number))).toString(2)
}
