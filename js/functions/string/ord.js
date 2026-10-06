// Viewi's PhpJsFunctions/Strings/Ord.js, for Fusion's values.
function ord(string) {
    /** Fusion: the first byte, as PHP (FunctionParityTest). */
    return P.utf8(P.toStr(string))[0] ?? 0;
}
