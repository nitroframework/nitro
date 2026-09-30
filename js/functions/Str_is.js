// Laravel's Str::is(): the pattern quoted, * any characters, #^...\z#su (i: isu).
// uses: _pcre
function Str_is(pattern, value, ignoreCase = false) {
    value = P.toStr(value);
    ignoreCase = P.bool(ignoreCase);
    for (let [, one] of P.entries(P.isArray(pattern) ? pattern : [pattern])) {
        one = P.toStr(one);
        if (one === '*' || one === value) return true;
        if (ignoreCase && P.fns.mb_strtolower(one) === P.fns.mb_strtolower(value)) return true;
        const regex = P.fns._pcre().quote(one).replace(/\\\*/g, '.*');
        if (new RegExp('^' + regex + '$', ignoreCase ? 'isu' : 'su').test(value)) return true;
    }
    return false;
}
