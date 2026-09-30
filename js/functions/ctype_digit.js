// Viewi's PhpJsFunctions/Ctype/CtypeDigit.js asks locutus' setlocale(); PHP's is C's isdigit() on
// each byte (FunctionParityTest): whether the text is all digits.
// uses: _ctype
function ctype_digit(text) {
    return P.fns._ctype(text, (c) => c >= 48 && c <= 57);
}
