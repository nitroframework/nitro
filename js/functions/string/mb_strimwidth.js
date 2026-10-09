// Nitro: PHP's mb_strimwidth(): the string from start (characters), cut to width, the trim
// marker in the width when it is cut.
// uses: _mb_char_width, mb_strwidth
function mb_strimwidth(string, start, width, trimMarker = '') {
    let chars = [...P.toStr(string)];
    start = P.toInt(start);
    width = P.toInt(width);
    trimMarker = P.toStr(trimMarker);
    if (start < 0) start += chars.length;
    if (start < 0 || start > chars.length) throw new P.PhpError('ValueError', 'mb_strimwidth(): Argument #2 ($start) is out of range');
    if (width < 0) throw new P.PhpError('Error', 'mb_strimwidth() with a negative width is not available in the browser');
    chars = chars.slice(start);
    if (P.fns.mb_strwidth(chars.join('')) <= width) return chars.join('');
    const budget = width - P.fns.mb_strwidth(trimMarker);
    let out = '';
    let used = 0;
    for (const c of chars) {
        const w = P.fns._mb_char_width(c.codePointAt(0));
        if (used + w > budget) break;
        out += c;
        used += w;
    }
    return out + trimMarker;
}
