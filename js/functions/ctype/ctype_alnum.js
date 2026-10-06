// Viewi's PhpJsFunctions/Ctype/CtypeAlnum.js asks locutus' setlocale(); PHP's is C's isalnum() on
// each byte (FunctionParityTest): whether the text is all alphanumeric.
// uses: _ctype
function ctype_alnum(text) {
    return P.fns._ctype(text, (c) => (c >= 48 && c <= 57) || (c >= 65 && c <= 90) || (c >= 97 && c <= 122));
}
