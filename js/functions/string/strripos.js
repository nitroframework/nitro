// Viewi's PhpJsFunctions/Strings/Strripos.js, for Nitro's values.
// uses: _ascii_lower
function strripos(haystack, needle, offset = 0) {
    /** Nitro: php.js's strrpos (bytes) with its offset. */
    return P.fns.strrpos(P.fns._ascii_lower(haystack), P.fns._ascii_lower(needle), offset);
}
