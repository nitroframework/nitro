// Laravel's Str::afterLast().
// uses: mb_strrpos, Str_substr, Str_length
function Str_afterLast(subject, search) {
    if (search === '') return subject;
    const position = P.fns.mb_strrpos(subject, search);
    if (position === false) return subject;
    return P.fns.Str_substr(subject, position + P.fns.Str_length(search));
}
