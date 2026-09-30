// Laravel's Str::chopEnd().
function Str_chopEnd(subject, needle) {
    for (const [, n] of P.entries(P.toArr(needle))) {
        if (n !== '' && P.fns.str_ends_with(subject, n)) return P.fns.mb_substr(subject, 0, -P.fns.mb_strlen(n));
    }
    return subject;
}
