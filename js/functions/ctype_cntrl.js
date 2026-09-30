// Viewi's PhpJsFunctions/Ctype/CtypeCntrl.js asks locutus' setlocale(); PHP's is C's iscntrl() on
// each byte (FunctionParityTest): whether the text is all control characters.
// uses: _ctype
function ctype_cntrl(text) {
    return P.fns._ctype(text, (c) => c < 32 || c === 127);
}
