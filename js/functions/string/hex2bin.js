// Viewi's PhpJsFunctions/Strings/Hex2bin.js (locutus), for Nitro's values.
function hex2bin(s) {
    const ret = [];
    let i = 0;
    let l;
    s = P.toStr(s);
    for (l = s.length; i < l; i += 2) {
        const c = parseInt(s.substr(i, 1), 16);
        const k = parseInt(s.substr(i + 1, 1), 16);
        if (isNaN(c) || isNaN(k)) return false;
        ret.push((c << 4) | k);
    }
    /** Nitro: bytes, as UTF-8 text (FunctionParityTest). */
    return P.fromUtf8(Uint8Array.from(ret));
}
