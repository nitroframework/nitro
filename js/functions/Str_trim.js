// Laravel's Str::trim().
// uses: _str_trim
function Str_trim(value, charlist = null) {
    return charlist === null ? P.fns._str_trim(value, true, true) : P.fns.trim(value, charlist);
}
