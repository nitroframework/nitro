// Fusion: PHP 8.4's mb_ucfirst(): the first character in title case.
// uses: mb_convert_case
function mb_ucfirst(string) {
    const chars = [...P.toStr(string)];
    return chars.length ? P.fns.mb_convert_case(chars[0], 2) + chars.slice(1).join('') : '';
}
