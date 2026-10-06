// Viewi's PhpJsFunctions/Ctype/CtypePunct.js asks locutus' setlocale(); PHP's is C's ispunct() on
// each byte (FunctionParityTest): whether the text is all punctuation.
// uses: _ctype
function ctype_punct(text) {
    return P.fns._ctype(text, (c) => (c > 32 && c < 48) || (c > 57 && c < 65) || (c > 90 && c < 97) || (c > 122 && c < 127));
}
