// Laravel's Str::isAscii() (voku's ASCII::is_ascii()).
function Str_isAscii(value) {
    return /^[\x00-\x7F]*$/.test(P.toStr(value));
}
