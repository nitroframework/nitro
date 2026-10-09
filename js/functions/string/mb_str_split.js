// Nitro: PHP's mb_str_split(): characters, length at a time.
function mb_str_split(string, length = 1) {
    const chars = [...P.toStr(string)];
    length = P.toInt(length);
    if (length < 1) throw new P.PhpError('ValueError', 'mb_str_split(): Argument #2 ($length) must be greater than 0');
    const out = [];
    for (let i = 0; i < chars.length; i += length) out.push(chars.slice(i, i + length).join(''));
    return out;
}
