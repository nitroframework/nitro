// Nitro: PHP's mb_strpos(): the position in characters, from offset (negative: from the end).
// uses: _mb_find
function mb_strpos(haystack, needle, offset = 0) {
    return P.fns._mb_find('mb_strpos', haystack, needle, offset, false, false);
}
