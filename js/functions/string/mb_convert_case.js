// Nitro (Viewi has no mbstring): PHP's mb_convert_case() for MB_CASE_UPPER (0), MB_CASE_LOWER
// (1) and MB_CASE_TITLE (2). Title case as mbstring's (php_unicode.c): a character after a cased
// one (case-ignorable ones, such as ' . and marks, in between) is lower case, else title case.
// uses: _mb_title
function mb_convert_case(string, mode) {
    string = P.toStr(string);
    mode = P.toInt(mode);
    if (mode === 0) return string.toUpperCase();
    if (mode === 1) return string.toLowerCase();
    if (mode !== 2) {
        throw new P.PhpError('Error', 'mb_convert_case() with this mode is not available in the browser: use MB_CASE_UPPER, MB_CASE_LOWER or MB_CASE_TITLE');
    }
    const titles = P.fns._mb_title();
    const cased = /\p{Cased}/u;
    const ignorable = /\p{Case_Ignorable}/u;
    const chars = [...string];
    let inWord = false;
    let out = '';
    chars.forEach((c, i) => {
        if (!inWord) {
            out += titles[c] ?? c.toUpperCase();
        } else if (c === 'Σ') {
            /** Σ at a word's end is ς (as mbstring's final sigma: no cased character after it, case-ignorable ones skipped). */
            let next = i + 1;
            while (next < chars.length && ignorable.test(chars[next])) next++;
            out += next < chars.length && cased.test(chars[next]) ? 'σ' : 'ς';
        } else {
            out += c.toLowerCase();
        }
        if (!ignorable.test(c)) inWord = cased.test(c);
    });
    return out;
}
