// Viewi's PhpJsFunctions/Strings/Strrchr.js (locutus), for Fusion's values.
function strrchr(haystack, needle) {
    /**
     * Fusion: bytes, as PHP: the needle's first byte ("\0" when it is empty; PHP 8 takes the
     * needle as a string, not a character code): FunctionParityTest.
     */
    const bytes = P.utf8(P.toStr(haystack));
    const pos = bytes.lastIndexOf(P.utf8(P.toStr(needle))[0] ?? 0);
    if (pos === -1) {
        return false;
    }

    return P.fromUtf8(bytes.subarray(pos));
}
