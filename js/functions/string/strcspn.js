// Viewi's PhpJsFunctions/Strings/Strcspn.js (locutus), for Fusion's values.
// uses: _span
function strcspn(str, mask, start = 0, length = null) {
    return P.fns._span(str, mask, start, length, false);
}
