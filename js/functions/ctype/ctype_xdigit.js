// Viewi's PhpJsFunctions/Ctype/CtypeXdigit.js asks locutus' setlocale(); PHP's is C's isxdigit() on
// each byte (FunctionParityTest): whether the text is all hexadecimal digits.
// uses: _ctype
function ctype_xdigit(text) {
    return P.fns._ctype(text, (c) => (c >= 48 && c <= 57) || (c >= 65 && c <= 70) || (c >= 97 && c <= 102));
}
