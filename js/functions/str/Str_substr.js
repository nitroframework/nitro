// Laravel's Str::substr().
function Str_substr(string, start, length = null) {
    return P.fns.mb_substr(string, start, length);
}
