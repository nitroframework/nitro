// Nitro: PHP's mb_substr_count(): how many times the needle is in the string (not overlapping).
function mb_substr_count(haystack, needle) {
    needle = P.toStr(needle);
    if (needle === '') throw new P.PhpError('ValueError', 'mb_substr_count(): Argument #2 ($needle) must not be empty');
    return P.toStr(haystack).split(needle).length - 1;
}
