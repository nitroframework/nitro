// Nitro: PHP's mb_strrpos(): the last position in characters (offset as strrpos()'s).
// uses: _mb_find
function mb_strrpos(haystack, needle, offset = 0) {
    return P.fns._mb_find('mb_strrpos', haystack, needle, offset, true, false);
}
