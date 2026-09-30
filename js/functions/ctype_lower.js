// Viewi's PhpJsFunctions/Ctype/CtypeLower.js asks locutus' setlocale(); PHP's is C's islower() on
// each byte (FunctionParityTest): whether the text is all lowercase letters.
// uses: _ctype
function ctype_lower(text) {
    return P.fns._ctype(text, (c) => c >= 97 && c <= 122);
}
