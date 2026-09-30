// Viewi's PhpJsFunctions/Ctype/CtypeAlpha.js asks locutus' setlocale(); PHP's is C's isalpha() on
// each byte (FunctionParityTest): whether the text is all letters.
// uses: _ctype
function ctype_alpha(text) {
    return P.fns._ctype(text, (c) => (c >= 65 && c <= 90) || (c >= 97 && c <= 122));
}
