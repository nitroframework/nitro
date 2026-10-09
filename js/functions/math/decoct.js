// Viewi's PhpJsFunctions/Math/Decoct.js, for Nitro's values.
function decoct(number) {
  //  discuss at: https://www.php.net/manual/en/function.decoct.php
  // The 64-bit unsigned value, as PHP: decoct(-1) is all ones.
  return BigInt.asUintN(64, BigInt(P.toInt(number))).toString(8)
}
