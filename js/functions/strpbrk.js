// Viewi's PhpJsFunctions/Strings/Strpbrk.js (locutus), for Fusion's values.
function strpbrk(haystack, charList) {
    /** Fusion: bytes, as PHP (FunctionParityTest); PHP's ValueError. */
    const bytes = P.utf8(P.toStr(haystack));
    const chars = P.utf8(P.toStr(charList));
    if (chars.length === 0) throw new P.PhpError('ValueError', 'strpbrk(): Argument #2 ($characters) must be a non-empty string');
    for (let i = 0, len = bytes.length; i < len; ++i) {
        if (chars.indexOf(bytes[i]) >= 0) {
            return P.fromUtf8(bytes.subarray(i));
        }
    }
    return false;
}
