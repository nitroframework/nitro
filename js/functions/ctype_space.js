// Viewi's PhpJsFunctions/Ctype/CtypeSpace.js asks locutus' setlocale(); PHP's is C's isspace() on
// each byte (FunctionParityTest): whether the text is all white space.
// uses: _ctype
function ctype_space(text) {
    return P.fns._ctype(text, (c) => (c >= 9 && c <= 13) || c === 32);
}
