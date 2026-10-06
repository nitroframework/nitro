// Viewi's PhpJsFunctions/Strings/Strcmp.js (locutus), for Fusion's values.
// uses: _bytes_cmp
function strcmp(str1, str2) {
    /** Fusion: PHP compares bytes (UTF-16 orders U+E000.. after astral characters): FunctionParityTest. */
    return P.fns._bytes_cmp(P.utf8(P.toStr(str1)), P.utf8(P.toStr(str2)), false);
}
