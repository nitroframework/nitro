// Viewi's PhpJsFunctions/Strings/Chr.js, for Nitro's values.
function chr(codePt) {
    const byte = ((P.toInt(codePt) % 256) + 256) % 256;
    /** Nitro: a byte from 128 on is no UTF-8 text alone; the server sends it as U+FFFD (FunctionParityTest). */
    return byte < 128 ? String.fromCharCode(byte) : '�';
}
