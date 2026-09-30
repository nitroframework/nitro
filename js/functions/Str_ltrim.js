// Laravel's Str::ltrim().
// uses: _str_trim
function Str_ltrim(value, charlist = null) {
    return charlist === null ? P.fns._str_trim(value, true, false) : P.fns.ltrim(value, charlist);
}
