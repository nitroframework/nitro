// Nitro: PHP's preg_match(), on _pcre_compile's patterns. It returns [result, matches]: the
// compiler assigns matches to preg_match()'s third argument (a reference in PHP).
// uses: _pcre_start, _pcre_exec, _pcre_groups
function preg_match(pattern, subject, flags = 0, offset = 0) {
    const start = P.fns._pcre_start(pattern, subject, offset);
    if (start === null) return [false, []];
    const { compiled, s, index } = start;
    if (index === null) return [false, []];
    const match = P.fns._pcre_exec(compiled, s.work, index);
    if (match === null) return [0, []];
    return [1, P.fns._pcre_groups(compiled, s, match, P.toInt(flags), true)];
}
