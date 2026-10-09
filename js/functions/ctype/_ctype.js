// Nitro: PHP's ctype functions: a string is non-empty and each of its bytes passes the test (in
// the C locale); an int from -128 to 255 is the character with that code (deprecated since PHP
// 8.1), another int its digits; anything else is false.
function _ctype(text, test) {
    if (Number.isInteger(text)) {
        if (text >= -128 && text <= 255) return test(text < 0 ? text + 256 : text);
        text = String(text);
    }
    if (typeof text !== 'string' || text === '') return false;
    return P.utf8(text).every(test);
}
