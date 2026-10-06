// Laravel's Str::startsWith().
function Str_startsWith(haystack, needles) {
    if (haystack === null || haystack === undefined) return false;
    for (const [, needle] of P.entries(P.isArray(needles) ? needles : [needles])) {
        if (P.toStr(needle) !== '' && P.fns.str_starts_with(haystack, needle)) return true;
    }
    return false;
}
