// Laravel's Str::contains().
function Str_contains(haystack, needles, ignoreCase = false) {
    if (haystack === null || haystack === undefined) return false;
    if (P.bool(ignoreCase)) haystack = P.fns.mb_strtolower(haystack);
    for (let [, needle] of P.entries(P.toArr(needles))) {
        if (P.bool(ignoreCase)) needle = P.fns.mb_strtolower(needle);
        if (needle !== '' && P.fns.str_contains(haystack, needle)) return true;
    }
    return false;
}
