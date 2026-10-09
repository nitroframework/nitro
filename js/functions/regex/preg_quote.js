// Viewi's PhpJsFunctions/Pcre/PregQuote.js, for Nitro's values.
function preg_quote(str, delimiter = null) {
    str = P.toStr(str);
    const special = '.\\+*?[^]$(){}=!<>|:-#';
    let out = '';
    for (const ch of str) {
        if (ch === '\0') {
            out += '\\000';
        } else if (special.indexOf(ch) !== -1 || (delimiter !== null && delimiter !== '' && ch === P.toStr(delimiter)[0])) {
            out += '\\' + ch;
        } else {
            out += ch;
        }
    }
    return out;
}
