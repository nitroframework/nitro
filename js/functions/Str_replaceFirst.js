// Laravel's Str::replaceFirst().
// uses: substr_replace
function Str_replaceFirst(search, replace, subject) {
    search = P.toStr(search);
    if (search === '') return subject;
    const position = P.fns.strpos(subject, search);
    if (position !== false) return P.fns.substr_replace(subject, replace, position, P.fns.strlen(search));
    return subject;
}
