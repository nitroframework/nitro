// Viewi's PhpJsFunctions/Strings/Strstr.js (locutus), for Nitro's values.
function strstr(haystack, needle, bool = false) {
    let pos = 0;

    haystack = P.toStr(haystack);
    pos = haystack.indexOf(P.toStr(needle));
    if (pos === -1) {
        return false;
    } else {
        if (P.bool(bool)) {
            return haystack.substr(0, pos);
        } else {
            return haystack.slice(pos);
        }
    }
}
