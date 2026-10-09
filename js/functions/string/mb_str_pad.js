// Nitro: PHP 8.3's mb_str_pad(): str_pad() counting characters.
function mb_str_pad(string, length, padString = ' ', padType = 1) {
    const chars = [...P.toStr(string)];
    const pad = [...P.toStr(padString)];
    padType = P.toInt(padType);
    if (pad.length === 0) throw new P.PhpError('ValueError', 'mb_str_pad(): Argument #3 ($pad_string) must be a non-empty string');
    if (padType < 0 || padType > 2) throw new P.PhpError('ValueError', 'mb_str_pad(): Argument #4 ($pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH');
    const total = P.toInt(length) - chars.length;
    if (total <= 0) return chars.join('');
    const make = (n) => Array.from({ length: n }, (_, i) => pad[i % pad.length]).join('');
    if (padType === 0) return make(total) + chars.join('');
    if (padType === 2) return make(Math.floor(total / 2)) + chars.join('') + make(Math.ceil(total / 2));
    return chars.join('') + make(total);
}
