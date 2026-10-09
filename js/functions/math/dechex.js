// Viewi's PhpJsFunctions/Math/Dechex.js, for Nitro's values.
function dechex(number) {
  //  discuss at: https://www.php.net/manual/en/function.dechex.php
  // The 64-bit unsigned value, as PHP: dechex(-1) is all ones.
  return BigInt.asUintN(64, BigInt(P.toInt(number))).toString(16)
}
