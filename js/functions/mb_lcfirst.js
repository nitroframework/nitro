// Fusion: PHP 8.4's mb_lcfirst(): the first character in lower case.
function mb_lcfirst(string) {
    const chars = [...P.toStr(string)];
    return chars.length ? chars[0].toLowerCase() + chars.slice(1).join('') : '';
}
