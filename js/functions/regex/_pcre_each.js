// Nitro: every match from index on, as PHP's global matching goes (php_pcre.c): after an empty
// match, one that is not empty at the same place, else one character on.
// uses: _pcre_exec
function _pcre_each(compiled, work, index, limit, each) {
    let notEmpty = false;
    let found = 0;
    while (limit < 0 || found < limit) {
        const match = P.fns._pcre_exec(compiled, work, index, notEmpty);
        if (match === null) {
            if (!notEmpty || index >= work.length || compiled.anchored) break;
            notEmpty = false;
            index += compiled.utf && work.codePointAt(index) > 0xFFFF ? 2 : 1;
            continue;
        }
        found++;
        each(match);
        const end = match.index + match[0].length;
        notEmpty = match[0].length === 0;
        index = end;
    }
    return found;
}
