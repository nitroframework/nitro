// Viewi's PhpJsFunctions/Ctype/CtypeGraph.js asks locutus' setlocale(); PHP's is C's isgraph() on
// each byte (FunctionParityTest): whether the text is all printable characters but space.
// uses: _ctype
function ctype_graph(text) {
    return P.fns._ctype(text, (c) => c > 32 && c < 127);
}
