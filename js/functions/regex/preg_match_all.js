// Fusion: PHP's preg_match_all(): [count, matches] (the compiler assigns matches to its third
// argument). PREG_PATTERN_ORDER (1, the default): each group's matches; PREG_SET_ORDER (2): each
// match's groups.
// uses: _pcre_start, _pcre_each, _pcre_groups
function preg_match_all(pattern, subject, flags = 0, offset = 0) {
    const start = P.fns._pcre_start(pattern, subject, offset);
    if (start === null) return [false, []];
    const { compiled, s, index } = start;
    if (index === null) return [false, []];
    flags = P.toInt(flags);
    const setOrder = (flags & 2) !== 0;
    if ((flags & 1) && setOrder) throw new P.PhpError('ValueError', 'preg_match_all(): Argument #4 ($flags) must be a PREG_* constant');
    const sets = [];
    P.fns._pcre_each(compiled, s.work, index, -1, (match) => sets.push(P.fns._pcre_groups(compiled, s, match, flags, setOrder)));
    if (setOrder) return [sets.length, sets];
    const byGroup = new Map();
    const names = new Map(compiled.names.map(([name, number]) => [number, name]));
    for (let k = 0; k <= compiled.groups; k++) {
        const list = sets.map((set) => P.get(set, k));
        if (names.has(k)) byGroup.set(names.get(k), list);
        byGroup.set(k, list);
    }
    return [sets.length, P.normalize(byGroup)];
}
