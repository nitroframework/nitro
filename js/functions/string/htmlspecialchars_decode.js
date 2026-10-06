// Viewi's PhpJsFunctions/Strings/HtmlspecialcharsDecode.js, for Fusion's values.
// uses: _php_html_unescape
function htmlspecialchars_decode(string, flags) { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.htmlspecialchars-decode.php
  return P.fns._php_html_unescape(string, flags, false)
}
