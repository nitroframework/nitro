// Viewi's PhpJsFunctions/Strings/StrWordCount.js drops "'" and "-" at every word's start and a
// "-" at every word's end, and has no "a..z" ranges: PHP only does so at the string's
// (FunctionParityTest). Fusion ports PHP's own (ext/standard/string.c), on bytes.
// uses: _charmask
function str_word_count(str, format = 0, charlist = null) {
    const bytes = P.utf8(P.toStr(str));
    format = P.toInt(format);
    if (format !== 0 && format !== 1 && format !== 2) {
        throw new P.PhpError('ValueError', 'str_word_count(): Argument #2 ($format) must be a valid format value');
    }
    const ch = charlist === null || charlist === undefined ? new Set() : P.fns._charmask(charlist);
    const isalpha = (c) => (c >= 65 && c <= 90) || (c >= 97 && c <= 122);
    const words = [];
    let p = 0;
    let e = bytes.length;
    /* first character cannot be ' or -, unless explicitly allowed by the user */
    if (p < e && ((bytes[p] === 39 && !ch.has(39)) || (bytes[p] === 45 && !ch.has(45)))) {
        p++;
    }
    /* last character cannot be -, unless explicitly allowed by the user */
    if (p < e && bytes[e - 1] === 45 && !ch.has(45)) {
        e--;
    }
    while (p < e) {
        const s = p;
        while (p < e && (isalpha(bytes[p]) || ch.has(bytes[p]) || bytes[p] === 39 || bytes[p] === 45)) {
            p++;
        }
        if (p > s) {
            words.push([s, P.fromUtf8(bytes.subarray(s, p))]);
        }
        p++;
    }
    if (format === 1) {
        return words.map((w) => w[1]);
    }
    if (format === 2) {
        return P.normalize(new Map(words));
    }
    return words.length;
}
