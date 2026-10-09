// Nitro: strspn() and strcspn() as PHP has them (Viewi's two, one way of offsets): the length of
// the start of the string's part [start, start + length) made of the mask's bytes (strspn) or of
// others (strcspn). Bytes, as PHP counts them: FunctionParityTest.
function _span(str, mask, start, length, inMask) {
    const bytes = P.utf8(P.toStr(str));
    const set = new Set(P.utf8(P.toStr(mask)));
    const n = bytes.length;
    start = P.toInt(start);
    if (start < 0) start = Math.max(0, n + start);
    if (start > n) return 0;
    let end = n;
    if (length !== null && length !== undefined) {
        length = P.toInt(length);
        end = length < 0 ? Math.max(start, n + length) : Math.min(n, start + length);
    }
    let i = start;
    while (i < end && set.has(bytes[i]) === inMask) i++;
    return i - start;
}
