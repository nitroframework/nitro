// Laravel's Str::containsAll().
// uses: Str_contains
function Str_containsAll(haystack, needles, ignoreCase = false) {
    let any = false;
    for (const [, needle] of P.entries(needles)) {
        any = true;
        if (!P.fns.Str_contains(haystack, needle, ignoreCase)) return false;
    }
    return any;
}
