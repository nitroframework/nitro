// Viewi's PhpJsFunctions/Strings/Strncasecmp.js (locutus), for Fusion's values.
// uses: _bytes_cmp
function strncasecmp(argStr1, argStr2, len) {
    len = P.toInt(len);
    /** Fusion: PHP's ValueError; bytes, ASCII letters lowered, as strcasecmp (FunctionParityTest). */
    if (len < 0) throw new P.PhpError('ValueError', 'strncasecmp(): Argument #3 ($length) must be greater than or equal to 0');
    return P.fns._bytes_cmp(P.utf8(P.toStr(argStr1)).slice(0, len), P.utf8(P.toStr(argStr2)).slice(0, len), true);
}
