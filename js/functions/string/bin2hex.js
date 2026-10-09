// Viewi's PhpJsFunctions/Strings/Bin2hex.js (locutus), for Nitro's values.
function bin2hex(s) {
    let o = '';
    /** Nitro: bytes, as PHP (FunctionParityTest). */
    for (const n of P.utf8(P.toStr(s))) {
        o += n.toString(16).padStart(2, '0');
    }
    return o;
}
