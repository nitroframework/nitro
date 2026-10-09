// Viewi's PhpJsFunctions/Strings/StrRot13.js (locutus), for Nitro's values.
function str_rot13(str) {
    return P.toStr(str)
        .replace(/[a-z]/gi, function (s) {
            return String.fromCharCode(s.charCodeAt(0) + (s.toLowerCase() < 'n' ? 13 : -13));
        });
}
