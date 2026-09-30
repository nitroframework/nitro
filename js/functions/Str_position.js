// Laravel's Str::position().
// uses: mb_strpos
function Str_position(haystack, needle, offset = 0) {
    return P.fns.mb_strpos(haystack, P.toStr(needle), offset);
}
