// Viewi's PhpJsFunctions/Strings/Quotemeta.js (locutus), for Nitro's values.
function quotemeta(str) {
    return P.toStr(str)
        .replace(/([.\\+*?[^\]$()])/g, '\\$1');
}
