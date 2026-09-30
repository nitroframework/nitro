// Laravel's Str::wrap().
function Str_wrap(value, before, after = null) {
    return P.toStr(before) + P.toStr(value) + P.toStr(after ?? before);
}
