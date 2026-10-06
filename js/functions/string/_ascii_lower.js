// Fusion: PHP 8.2+'s lowering in the case-insensitive functions: ASCII letters only.
function _ascii_lower(s) {
    return P.toStr(s).replace(/[A-Z]/g, (c) => String.fromCharCode(c.charCodeAt(0) + 32));
}
