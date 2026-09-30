// Fusion: PHP's preg_grep(): the entries (keys kept) whose value matches; PREG_GREP_INVERT (1)
// those that do not.
// uses: _pcre_start, _pcre_exec
function preg_grep(pattern, array, flags = 0) {
    const invert = (P.toInt(flags) & 1) !== 0;
    const entries = [];
    for (const [k, v] of P.entries(array)) {
        const start = P.fns._pcre_start(pattern, v);
        if (start === null) return false;
        const matched = P.fns._pcre_exec(start.compiled, start.s.work, 0) !== null;
        if (matched !== invert) entries.push([k, v]);
    }
    return P.normalize(new Map(entries));
}
