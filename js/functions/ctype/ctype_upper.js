// Viewi's PhpJsFunctions/Ctype/CtypeUpper.js asks locutus' setlocale(); PHP's is C's isupper() on
// each byte (FunctionParityTest): whether the text is all uppercase letters.
// uses: _ctype
function ctype_upper(text) {
    return P.fns._ctype(text, (c) => c >= 65 && c <= 90);
}
