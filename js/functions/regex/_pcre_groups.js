// Fusion: PHP's array of a match (make_subpats): 0 then each group, a named group's name before
// its number; an unset group "" (null with PREG_UNMATCHED_AS_NULL), with PREG_OFFSET_CAPTURE
// [value, byte offset] (-1 when unset). trim: the unset groups after the last set one are left out
// (as preg_match() does without PREG_UNMATCHED_AS_NULL).
function _pcre_groups(compiled, subject, match, flags, trim) {
    const offsetCapture = (flags & 256) !== 0;
    const asNull = (flags & 512) !== 0;
    const names = new Map(compiled.names.map(([name, number]) => [number, name]));
    const span = (k) => (k === 0 ? [match.index, match.index + match[0].length] : match.indices.groups?.[`__g${k}`]);
    let count = compiled.groups;
    if (trim && !asNull) while (count > 0 && span(count) === undefined) count--;
    const map = new Map();
    for (let k = 0; k <= count; k++) {
        const at = span(k);
        const value = at === undefined ? (asNull ? null : '') : subject.piece(at[0], at[1]);
        const item = offsetCapture ? [value, at === undefined ? -1 : subject.offset(at[0])] : value;
        if (names.has(k)) map.set(names.get(k), item);
        map.set(k, item);
    }
    return P.normalize(map);
}
