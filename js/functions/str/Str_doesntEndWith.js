// Laravel's Str::doesntEndWith().
// uses: Str_endsWith
function Str_doesntEndWith(haystack, needles) {
    return !P.fns.Str_endsWith(haystack, needles);
}
