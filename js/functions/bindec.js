// Viewi's PhpJsFunctions/Math/Bindec.js, for Fusion's values.
function bindec(str) {
  //  discuss at: https://www.php.net/manual/en/function.bindec.php
  // Characters that are not base-2 digits are ignored, as PHP does; '' is 0.
  const digits = P.toStr(str).replace(/[^01]/g, '')
  return digits === '' ? 0 : parseInt(digits, 2)
}
