// Fusion: PHP's preg_split() (php_pcre_split_impl): PREG_SPLIT_NO_EMPTY (1),
// PREG_SPLIT_DELIM_CAPTURE (2), PREG_SPLIT_OFFSET_CAPTURE (4).
// uses: _pcre_start, _pcre_exec
function preg_split(pattern, subject, limit = -1, flags = 0) {
    const start = P.fns._pcre_start(pattern, subject);
    if (start === null) return false;
    const { compiled, s } = start;
    flags = P.toInt(flags);
    const noEmpty = (flags & 1) !== 0;
    const delimCapture = (flags & 2) !== 0;
    const offsetCapture = (flags & 4) !== 0;
    let limitVal = P.toInt(limit);
    if (limitVal === 0) limitVal = -1;
    const out = [];
    const add = (from, to) => out.push(offsetCapture ? [s.piece(from, to), s.offset(from)] : s.piece(from, to));
    const work = s.work;
    let lastMatchOffset = 0;
    let startOffset = 0;
    let notEmpty = false;
    if (limitVal === -1 || limitVal > 1) {
        while (limitVal === -1 || limitVal > 1) {
            const match = P.fns._pcre_exec(compiled, work, startOffset, notEmpty);
            if (match === null) {
                if (!notEmpty) break;
                /* after an empty match: one character on, unless at the end */
                notEmpty = false;
                if (startOffset < work.length) startOffset = s.next(startOffset);
                else break;
                continue;
            }
            const matchStart = match.index;
            const matchEnd = match.index + match[0].length;
            if (!noEmpty || matchStart !== lastMatchOffset) {
                add(lastMatchOffset, matchStart);
                /* One less left to do */
                if (limitVal !== -1) limitVal--;
            }
            if (delimCapture) {
                for (let k = 1; k <= compiled.groups; k++) {
                    const at = match.indices.groups?.[`__g${k}`];
                    if (at === undefined) {
                        /* PHP adds the groups up to the last one that matched */
                        let last = compiled.groups;
                        while (last > 0 && match.indices.groups?.[`__g${last}`] === undefined) last--;
                        if (k > last) break;
                        if (!noEmpty) out.push(offsetCapture ? ['', -1] : '');
                        continue;
                    }
                    if (!noEmpty || at[1] > at[0]) add(at[0], at[1]);
                }
            }
            lastMatchOffset = matchEnd;
            startOffset = matchEnd;
            notEmpty = matchEnd === matchStart;
        }
    }
    if (!noEmpty || lastMatchOffset < work.length) add(lastMatchOffset, work.length);
    return out;
}
