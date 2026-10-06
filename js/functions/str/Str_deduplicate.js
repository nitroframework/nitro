// Laravel's Str::deduplicate(): each character's repeats (preg_quote($c).'+': the last of a
// longer string repeats).
// uses: _pcre
function Str_deduplicate(string, characters = ' ') {
    const once = (s, c) => s.replace(new RegExp(P.fns._pcre().quote(c) + '+', 'gu'), () => c);
    if (!P.isArray(characters)) return once(P.toStr(string), P.toStr(characters));
    return P.entries(characters).reduce((carry, [, c]) => once(carry, P.toStr(c)), P.toStr(string));
}
