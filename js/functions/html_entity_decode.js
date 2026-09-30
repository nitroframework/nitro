// Viewi's PhpJsFunctions/Strings/HtmlEntityDecode.js, for Fusion's values.
// uses: _php_html_unescape
function html_entity_decode(string, flags) { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.html-entity-decode.php
  return P.fns._php_html_unescape(string, flags, true)
}
