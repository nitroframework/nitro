// Laravel's Str::ascii() (voku's ASCII::to_ascii()), for "en": its table (_str_ascii), character
// by character; the other characters beyond ASCII it drops.
// uses: _str_ascii
function Str_ascii(value, language = 'en') {
    if (language !== 'en') {
        throw new P.PhpError('Error', 'Str::ascii() in the browser transliterates as English ("en") only');
    }
    const table = P.fns._str_ascii();
    let out = '';
    for (const c of P.toStr(value)) out += table[c] ?? (c.codePointAt(0) < 0x80 ? c : '');
    return out;
}
