// Viewi's PhpJsFunctions/Strings/SubstrCompare.js (locutus), for Fusion's values.
// uses: _bytes_cmp
function substr_compare(mainStr, str, offset, length = null, caseInsensitivity = false) {
    /** Fusion: bytes, PHP's offsets and errors, and its results (strcmp's, strcasecmp's): FunctionParityTest. */
    const main = P.utf8(P.toStr(mainStr));
    let other = P.utf8(P.toStr(str));
    offset = P.toInt(offset);
    if (offset < 0) {
        offset = Math.max(0, main.length + offset);
    }
    if (offset > main.length) {
        throw new P.PhpError('ValueError', 'substr_compare(): Argument #3 ($offset) must be contained in argument #1 ($main_str)');
    }
    if (length !== null && length !== undefined) {
        length = P.toInt(length);
        if (length < 0) throw new P.PhpError('ValueError', 'substr_compare(): Argument #4 ($length) must be greater than or equal to 0');
    }
    const len = length === null || length === undefined ? Math.max(main.length - offset, other.length) : length;
    other = other.slice(0, len);
    return P.fns._bytes_cmp(main.slice(offset, offset + len), other, P.bool(caseInsensitivity));
}
