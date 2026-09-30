// Laravel's Str::rtrim().
// uses: _str_trim
function Str_rtrim(value, charlist = null) {
    return charlist === null ? P.fns._str_trim(value, false, true) : P.fns.rtrim(value, charlist);
}
