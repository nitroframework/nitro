// Fusion: PHP's mb_stripos(): as mb_strpos(), case-folded.
// uses: _mb_find
function mb_stripos(haystack, needle, offset = 0) {
    return P.fns._mb_find('mb_stripos', haystack, needle, offset, false, true);
}
