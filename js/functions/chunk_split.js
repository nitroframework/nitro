// Viewi's PhpJsFunctions/Strings/ChunkSplit.js (locutus), for Fusion's values.
function chunk_split(body, chunklen = 76, end = '\r\n') {
    chunklen = P.toInt(chunklen);
    /** Fusion: PHP's ValueError; bytes, newlines included; an empty string is the end once (FunctionParityTest). */
    if (chunklen < 1) throw new P.PhpError('ValueError', 'chunk_split(): Argument #2 ($length) must be greater than 0');
    const bytes = P.utf8(P.toStr(body));
    if (chunklen > bytes.length) return P.toStr(body) + P.toStr(end);
    let out = '';
    for (let i = 0; i < bytes.length; i += chunklen) {
        out += P.fromUtf8(bytes.subarray(i, i + chunklen)) + P.toStr(end);
    }
    return out;
}
