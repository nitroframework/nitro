// Viewi's PhpJsFunctions/Url/Base64Decode.js, for Fusion's values.
function base64_decode(str, strict) { // eslint-disable-line camelcase
  //  discuss at: https://www.php.net/manual/en/function.base64-decode.php
  // Characters outside the alphabet are skipped (strict: false returned); missing padding is fine.
  // The bytes are read as UTF-8 ('w6k=' is 'é'), or Latin-1 when they are not valid UTF-8.
  str = P.toStr(str)
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'
  let bits = 0
  let count = 0
  const bytes = []
  for (const ch of str) {
    const v = alphabet.indexOf(ch)
    if (v === -1) {
      if (strict && ch !== '=' && !/\s/.test(ch)) {
        return false
      }
      continue
    }
    bits = (bits << 6) | v
    count += 6
    if (count >= 8) {
      count -= 8
      bytes.push((bits >> count) & 255)
    }
  }
  /** Fusion: the bytes as UTF-8 text (the server sends invalid bytes as U+FFFD): FunctionParityTest. */
  return P.fromUtf8(Uint8Array.from(bytes))
}
