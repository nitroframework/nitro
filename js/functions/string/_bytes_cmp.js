// Nitro: PHP's zend_binary_strcmp / zend_binary_strcasecmp on UTF-8 bytes. strcmp() gives -1, 0
// or 1 (PHP 8.2+); strcasecmp() the difference of the first bytes that differ (ASCII letters
// lowered), else -1, 0 or 1 by length: FunctionParityTest.
function _bytes_cmp(bytes1, bytes2, caseless) {
    const lower = (b) => (caseless && b >= 65 && b <= 90 ? b + 32 : b);
    const n = Math.min(bytes1.length, bytes2.length);
    for (let i = 0; i < n; i++) {
        const c1 = lower(bytes1[i]);
        const c2 = lower(bytes2[i]);
        if (c1 !== c2) return caseless ? c1 - c2 : c1 < c2 ? -1 : 1;
    }
    return Math.sign(bytes1.length - bytes2.length);
}
