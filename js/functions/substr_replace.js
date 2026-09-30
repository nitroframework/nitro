// Viewi's PhpJsFunctions/Strings/SubstrReplace.js (locutus), for Fusion's values.
function substr_replace(str, replace, start, length = null) {
    /** Fusion: bytes, and PHP's clamping of start and length (FunctionParityTest). */
    const bytes = P.utf8(P.toStr(str));
    const n = bytes.length;
    start = P.toInt(start);
    if (start < 0) {
        start = Math.max(0, start + n);
    } else if (start > n) {
        start = n;
    }
    length = length === null || length === undefined ? n : P.toInt(length);
    if (length < 0) {
        length = Math.max(0, length + n - start);
    }
    length = Math.min(length, n - start);
    return P.fromUtf8(bytes.subarray(0, start)) + P.toStr(replace) + P.fromUtf8(bytes.subarray(start + length));
}
