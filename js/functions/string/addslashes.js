// Viewi's PhpJsFunctions/Strings/Addslashes.js (locutus), for Nitro's values.
function addslashes(str) {
    return P.toStr(str)
        .replace(/[\\"']/g, '\\$&')
        .replace(/\u0000/g, '\\0');
}
