// Fusion: PHP's php_charmask: the bytes of a character list, "a..z" a range.
function _charmask(list) {
    const bytes = P.utf8(P.toStr(list));
    const mask = new Set();
    for (let i = 0; i < bytes.length; i++) {
        if (i + 3 < bytes.length && bytes[i + 1] === 46 && bytes[i + 2] === 46 && bytes[i + 3] >= bytes[i]) {
            for (let c = bytes[i]; c <= bytes[i + 3]; c++) mask.add(c);
            i += 3;
        } else {
            mask.add(bytes[i]);
        }
    }
    return mask;
}
