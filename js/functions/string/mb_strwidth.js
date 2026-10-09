// Nitro: PHP's mb_strwidth(): East Asian wide and fullwidth characters count 2, others 1.
// uses: _mb_char_width
function mb_strwidth(string) {
    let width = 0;
    for (const c of P.toStr(string)) width += P.fns._mb_char_width(c.codePointAt(0));
    return width;
}
