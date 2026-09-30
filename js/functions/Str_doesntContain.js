// Laravel's Str::doesntContain().
// uses: Str_contains
function Str_doesntContain(haystack, needles, ignoreCase = false) {
    return !P.fns.Str_contains(haystack, needles, ignoreCase);
}
