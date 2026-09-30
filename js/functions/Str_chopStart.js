// Laravel's Str::chopStart().
function Str_chopStart(subject, needle) {
    for (const [, n] of P.entries(P.toArr(needle))) {
        if (n !== '' && P.fns.str_starts_with(subject, n)) return P.fns.mb_substr(subject, P.fns.mb_strlen(n));
    }
    return subject;
}
