// Viewi's PhpJsFunctions/Strings/Strncmp.js, for Nitro's values.
// uses: _bytes_cmp
function strncmp(str1, str2, len) {
    len = P.toInt(len);
    /** Nitro: PHP's ValueError; and bytes, as strcmp (FunctionParityTest). */
    if (len < 0) throw new P.PhpError('ValueError', 'strncmp(): Argument #3 ($length) must be greater than or equal to 0');
    return P.fns._bytes_cmp(P.utf8(P.toStr(str1)).slice(0, len), P.utf8(P.toStr(str2)).slice(0, len), false);
}
