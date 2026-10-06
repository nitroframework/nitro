// Laravel's Str::replace(), with replaceWhileIgnoringCase(): ASCII search terms as str_ireplace()
// does, others with a case-insensitive Unicode pattern.
// uses: Str_isAscii, _pcre
function Str_replace(search, replace, subject, caseSensitive = true) {
    if (P.bool(caseSensitive)) return P.fns.str_replace(search, replace, subject);
    if (!P.isArray(search) && P.isArray(replace)) return P.fns.str_ireplace(search, replace, subject);
    const searches = P.isArray(search) ? P.entries(search).map(([, v]) => v) : [search];
    if (searches.every((term) => P.fns.Str_isAscii(term))) return P.fns.str_ireplace(search, replace, subject);
    const replacements = P.isArray(replace) ? P.entries(replace).map(([, v]) => v) : searches.map(() => replace);
    searches.forEach((term, index) => {
        term = P.toStr(term);
        if (term === '') return;
        const replacement = P.toStr(replacements[index] ?? '');
        if (P.fns.Str_isAscii(term)) {
            subject = P.fns.str_ireplace(term, replacement, subject);
            return;
        }
        const pattern = new RegExp(P.fns._pcre().quote(term), 'giu');
        const each = (s) => P.toStr(s).replace(pattern, () => replacement);
        subject = P.isArray(subject) ? P.normalize(new Map(P.entries(subject).map(([k, v]) => [k, each(v)]))) : each(subject);
    });
    return subject;
}
