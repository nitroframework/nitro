// Viewi's PhpJsFunctions/Strings/Stristr.js (locutus), for Nitro's values.
// uses: _ascii_lower
function stristr(haystack, needle, bool = false) {
    let pos = 0;

    haystack = P.toStr(haystack);
    /** Nitro: ASCII letters only, as PHP 8.2+ (FunctionParityTest). */
    pos = P.fns._ascii_lower(haystack).indexOf(P.fns._ascii_lower(needle));
    if (pos === -1) {
        return false;
    } else {
        if (P.bool(bool)) {
            return haystack.substr(0, pos);
        } else {
            return haystack.slice(pos);
        }
    }
}
