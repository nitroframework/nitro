// Laravel's Str::squish().
// uses: Str_trim, _pcre
function Str_squish(value) {
    return P.fns.Str_trim(value).replace(new RegExp('(?:[' + P.fns._pcre().ws + ']|\\u3164|\\u1160)+', 'gu'), ' ');
}
