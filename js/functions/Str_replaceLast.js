// Laravel's Str::replaceLast().
// uses: substr_replace
function Str_replaceLast(search, replace, subject) {
    search = P.toStr(search);
    if (search === '') return subject;
    const position = P.fns.strrpos(subject, search);
    if (position !== false) return P.fns.substr_replace(subject, replace, position, P.fns.strlen(search));
    return subject;
}
