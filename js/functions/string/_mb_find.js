// Nitro: mb_strpos() and mb_strrpos() on characters, with PHP's offsets and errors.
function _mb_find(fn, haystack, needle, offset, last, caseless) {
    const fold = (s) => (caseless ? s.toLowerCase() : s);
    const h = [...fold(P.toStr(haystack))];
    const n = [...fold(P.toStr(needle))];
    offset = P.toInt(offset);
    if (offset > h.length || -offset > h.length) {
        throw new P.PhpError('ValueError', `${fn}(): Argument #3 ($offset) must be contained in argument #1 ($haystack)`);
    }
    const at = (i) => n.every((c, j) => h[i + j] === c);
    if (!last) {
        for (let i = offset < 0 ? h.length + offset : offset; i <= h.length - n.length; i++) if (at(i)) return i;
        return false;
    }
    let from = 0;
    let end = h.length;
    if (offset >= 0) from = offset;
    else if (-offset >= n.length) end = h.length + offset + n.length;
    for (let i = end - n.length; i >= from; i--) if (at(i)) return i;
    return false;
}
