// Laravel's Str::endsWith().
function Str_endsWith(haystack, needles) {
    if (haystack === null || haystack === undefined) return false;
    for (const [, needle] of P.entries(P.toArr(needles))) {
        if (P.toStr(needle) !== '' && P.fns.str_ends_with(haystack, needle)) return true;
    }
    return false;
}
