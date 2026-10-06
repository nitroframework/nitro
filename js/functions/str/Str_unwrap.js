// Laravel's Str::unwrap().
// uses: Str_startsWith, Str_endsWith, Str_substr, Str_length
function Str_unwrap(value, before, after = null) {
    if (P.fns.Str_startsWith(value, before)) value = P.fns.Str_substr(value, P.fns.Str_length(before));
    after = after ?? before;
    if (P.fns.Str_endsWith(value, after)) value = P.fns.Str_substr(value, 0, -P.fns.Str_length(after));
    return value;
}
