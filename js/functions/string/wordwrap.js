// Viewi's PhpJsFunctions/Strings/Wordwrap.js (locutus) wraps otherwise than PHP (a break after
// the space, width 0, the spaces it keeps: FunctionParityTest): Nitro ports PHP's own
// (ext/standard/string.c), on bytes.
function wordwrap(str, intWidth = 75, strBreak = '\n', cut = false) {
    const text = P.utf8(P.toStr(str));
    const linelength = P.toInt(intWidth);
    const breakchar = P.utf8(P.toStr(strBreak));
    const docut = P.bool(cut);
    const len = text.length;
    if (len === 0) {
        return '';
    }
    if (breakchar.length === 0) {
        throw new P.PhpError('ValueError', 'wordwrap(): Argument #3 ($break) cannot be empty');
    }
    if (linelength === 0 && docut) {
        throw new P.PhpError('ValueError', 'wordwrap(): Argument #4 ($cut_long_words) cannot be true when argument #2 ($width) is 0');
    }
    /* Special case for a single-character break as it needs no additional storage space */
    if (breakchar.length === 1 && !docut) {
        const newtext = text.slice();
        let laststart = 0;
        let lastspace = 0;
        for (let current = 0; current < len; current++) {
            if (text[current] === breakchar[0]) {
                laststart = lastspace = current + 1;
            } else if (text[current] === 32) {
                if (current - laststart >= linelength) {
                    newtext[current] = breakchar[0];
                    laststart = current + 1;
                }
                lastspace = current;
            } else if (current - laststart >= linelength && laststart !== lastspace) {
                newtext[lastspace] = breakchar[0];
                laststart = lastspace + 1;
            }
        }
        return P.fromUtf8(newtext);
    }
    /* Multiple character line break or forced cut */
    const out = [];
    const copy = (from, to) => {
        for (let i = from; i < to; i++) out.push(text[i]);
    };
    const brk = () => out.push(...breakchar);
    const isBreakAt = (current) => current + breakchar.length < len && breakchar.every((b, i) => text[current + i] === b);
    let laststart = 0;
    let lastspace = 0;
    let current = 0;
    for (current = 0; current < len; current++) {
        /* when we hit an existing break, copy to new buffer, and fix up laststart and lastspace */
        if (text[current] === breakchar[0] && isBreakAt(current)) {
            copy(laststart, current + breakchar.length);
            current += breakchar.length - 1;
            laststart = lastspace = current + 1;
        } else if (text[current] === 32) {
            /* if it is a space, check if it is at the line boundary, copy and insert a break, or just keep track of it */
            if (current - laststart >= linelength) {
                copy(laststart, current);
                brk();
                laststart = current + 1;
            }
            lastspace = current;
        } else if (current - laststart >= linelength && docut && laststart >= lastspace) {
            /* if we are cutting, and we've accumulated enough characters, and we can't land on a space, copy and insert a break. */
            copy(laststart, current);
            brk();
            laststart = lastspace = current;
        } else if (current - laststart >= linelength && laststart < lastspace) {
            /* if the current word puts us over the linelength, copy back up until the last space, insert a break, and move up the laststart */
            copy(laststart, lastspace);
            brk();
            laststart = lastspace = lastspace + 1;
        }
    }
    /* copy over any stragglers */
    if (laststart !== current) {
        copy(laststart, current);
    }
    return P.fromUtf8(Uint8Array.from(out));
}
