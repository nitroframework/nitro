// Viewi's PhpJsFunctions/Strings/Addslashes.js (locutus), for Fusion's values.
function addslashes(str) {
    return P.toStr(str)
        .replace(/[\\"']/g, '\\$&')
        .replace(/\u0000/g, '\\0');
}
