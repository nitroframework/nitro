// Viewi's PhpJsFunctions/Strings/Quotemeta.js (locutus), for Fusion's values.
function quotemeta(str) {
    return P.toStr(str)
        .replace(/([.\\+*?[^\]$()])/g, '\\$1');
}
