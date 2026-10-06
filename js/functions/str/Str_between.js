// Laravel's Str::between().
// uses: Str_beforeLast, Str_after
function Str_between(subject, from, to) {
    if (from === '' || to === '') return subject;
    return P.fns.Str_beforeLast(P.fns.Str_after(subject, from), to);
}
