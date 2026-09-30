// Viewi's PhpJsFunctions/Ctype/CtypePrint.js asks locutus' setlocale(); PHP's is C's isprint() on
// each byte (FunctionParityTest): whether the text is all printable characters.
// uses: _ctype
function ctype_print(text) {
    return P.fns._ctype(text, (c) => c >= 32 && c < 127);
}
