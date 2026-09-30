// Viewi's PhpJsFunctions/Url/HttpBuildQuery.js, for Fusion's values.
// uses: urlencode, rawurlencode
function http_build_query(data, numericPrefix, separator, encType) { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.http-build-query.php
  // Nested arrays become a[0]=1&b[c]=d (brackets encoded), true/false are 1/0, null is skipped;
  // numericPrefix goes before top-level integer keys. encType PHP_QUERY_RFC1738 1 (default,
  // spaces as +) or PHP_QUERY_RFC3986 2 (spaces as %20).
  separator = separator === undefined || separator === null || separator === '' ? '&' : separator
  const encode = encType === 2 ? P.fns.rawurlencode : P.fns.urlencode
  const pairs = []
  const add = function (key, value) {
    if (value === null || value === undefined) {
      return
    }
    if (typeof value === 'object') {
      for (const [k, v] of P.entries(value)) {
        add(key + '[' + k + ']', v)
      }
      return
    }
    const scalar = value === true ? '1' : (value === false ? '0' : P.toStr(value))
    pairs.push(encode(key) + '=' + encode(scalar))
  }
  for (const [key, value] of P.entries(data)) {
    add((typeof key === 'number' && numericPrefix ? numericPrefix : '') + key, value)
  }
  return pairs.join(separator)
}
