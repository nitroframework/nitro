// Viewi's PhpJsFunctions/Strings/StripTags.js (locutus) removes tags with regular expressions,
// which a quoted ">" in an attribute, an unclosed comment, "<<b>>" or a doctype get past
// (FunctionParityTest): Fusion ports PHP's own state machine (php_strip_tags_ex in
// ext/standard/string.c). Allowed tags are not taken.
function strip_tags(input, allowed = null) {
    if (allowed !== null && allowed !== undefined) {
        throw new P.PhpError('Error', 'strip_tags() with allowed tags is not available in the browser');
    }
    const buf = P.toStr(input);
    const end = buf.length;
    const isspace = (c) => c === ' ' || c === '\t' || c === '\n' || c === '\v' || c === '\f' || c === '\r';
    const at = (i) => (i >= 0 && i < end ? buf[i] : '\0');
    let rp = '';
    let lc = '\0';
    let br = 0;
    let depth = 0;
    let inQ = '';
    let isXml = false;
    let state = 0;
    let p = 0;

    while (p < end) {
        const c = buf[p];
        if (state === 0) {
            switch (c) {
                case '\0':
                    break;
                case '<':
                    if (inQ) break;
                    if (isspace(at(p + 1))) {
                        rp += c;
                        break;
                    }
                    lc = '<';
                    state = 1;
                    break;
                case '>':
                    if (depth) {
                        depth--;
                        break;
                    }
                    if (inQ) break;
                    rp += c;
                    break;
                default:
                    rp += c;
                    break;
            }
        } else if (state === 1) {
            switch (c) {
                case '\0':
                    break;
                case '<':
                    if (inQ) break;
                    if (isspace(at(p + 1))) break;
                    depth++;
                    break;
                case '>':
                    if (depth) {
                        depth--;
                        break;
                    }
                    if (inQ) break;
                    lc = '>';
                    if (isXml && at(p - 1) === '-') break;
                    inQ = '';
                    state = 0;
                    isXml = false;
                    break;
                case '"':
                case '\'':
                    if (p !== 0 && (!inQ || c === inQ)) {
                        inQ = inQ ? '' : c;
                    }
                    break;
                case '!':
                    /* JavaScript & Other HTML scripting languages */
                    if (at(p - 1) === '<') {
                        state = 3;
                        lc = c;
                    }
                    break;
                case '?':
                    if (at(p - 1) === '<') {
                        br = 0;
                        state = 2;
                    }
                    break;
                default:
                    break;
            }
        } else if (state === 2) {
            switch (c) {
                case '(':
                    if (lc !== '"' && lc !== '\'') {
                        lc = '(';
                        br++;
                    }
                    break;
                case ')':
                    if (lc !== '"' && lc !== '\'') {
                        lc = ')';
                        br--;
                    }
                    break;
                case '>':
                    if (depth) {
                        depth--;
                        break;
                    }
                    if (inQ) break;
                    if (!br && lc !== '"' && at(p - 1) === '?') {
                        inQ = '';
                        state = 0;
                    }
                    break;
                case '"':
                case '\'':
                    if (p >= 1 && at(p - 1) !== '\\') {
                        if (lc === c) {
                            lc = '\0';
                        } else if (lc !== '\\') {
                            lc = c;
                        }
                        if (p !== 0 && (!inQ || c === inQ)) {
                            inQ = inQ ? '' : c;
                        }
                    }
                    break;
                case 'l':
                case 'L':
                    /* If we encounter '<?xml' then we shouldn't be in state == 2 (PHP). Switch back to HTML. */
                    if (p > 4 && (at(p - 1) === 'm' || at(p - 1) === 'M') && (at(p - 2) === 'x' || at(p - 2) === 'X') && at(p - 3) === '?' && at(p - 4) === '<') {
                        state = 1;
                        isXml = true;
                    }
                    break;
                default:
                    break;
            }
        } else if (state === 3) {
            switch (c) {
                case '>':
                    if (depth) {
                        depth--;
                        break;
                    }
                    if (inQ) break;
                    inQ = '';
                    state = 0;
                    break;
                case '"':
                case '\'':
                    if (p !== 0 && at(p - 1) !== '\\' && (!inQ || c === inQ)) {
                        inQ = inQ ? '' : c;
                    }
                    break;
                case '-':
                    if (p >= 2 && at(p - 1) === '-' && at(p - 2) === '!') {
                        state = 4;
                    }
                    break;
                case 'E':
                case 'e':
                    /* !DOCTYPE exception */
                    if (p > 6 && /^doctyp$/i.test(buf.slice(p - 6, p))) {
                        state = 1;
                    }
                    break;
                default:
                    break;
            }
        } else if (c === '>' && !inQ && p >= 2 && at(p - 1) === '-' && at(p - 2) === '-') {
            /* state 4: a comment, until --> */
            inQ = '';
            state = 0;
        }
        p++;
    }

    return rp;
}
