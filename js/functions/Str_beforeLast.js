// Laravel's Str::beforeLast().
// uses: mb_strrpos, Str_substr
function Str_beforeLast(subject, search) {
    if (search === '') return subject;
    const pos = P.fns.mb_strrpos(subject, search);
    if (pos === false) return subject;
    return P.fns.Str_substr(subject, 0, pos);
}
