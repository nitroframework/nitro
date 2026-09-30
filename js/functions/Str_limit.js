// Laravel's Str::limit(). The last pattern has no /u: PCRE's \s is then ASCII white space.
// uses: mb_strwidth, mb_strimwidth, strip_tags
function Str_limit(value, limit = 100, end = '...', preserveWords = false) {
    value = P.toStr(value);
    end = P.toStr(end);
    if (P.lte(P.fns.mb_strwidth(value), limit)) return value;
    if (!P.bool(preserveWords)) return P.fns.rtrim(P.fns.mb_strimwidth(value, 0, limit, '')) + end;
    value = P.fns.trim(P.fns.strip_tags(value).replace(/[\n\r]+/g, ' '));
    const trimmed = P.fns.rtrim(P.fns.mb_strimwidth(value, 0, limit, ''));
    if (P.fns.mb_substr(value, limit, 1) === ' ') return trimmed + end;
    return trimmed.replace(/([^\n]*)[\t\n\x0B\f\r ][^\n]*/g, '$1') + end;
}
