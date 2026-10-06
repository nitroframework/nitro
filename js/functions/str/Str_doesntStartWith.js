// Laravel's Str::doesntStartWith().
// uses: Str_startsWith
function Str_doesntStartWith(haystack, needles) {
    return !P.fns.Str_startsWith(haystack, needles);
}
