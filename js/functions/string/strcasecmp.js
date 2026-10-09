// Viewi's PhpJsFunctions/Strings/Strcasecmp.js (locutus), for Nitro's values.
// uses: _bytes_cmp
function strcasecmp(fString1, fString2) {
    /** Nitro: bytes, ASCII letters lowered, as PHP 8.2+ (FunctionParityTest). */
    return P.fns._bytes_cmp(P.utf8(P.toStr(fString1)), P.utf8(P.toStr(fString2)), true);
}
