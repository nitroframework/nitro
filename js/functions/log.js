// Viewi's PhpJsFunctions/Math/Log.js (locutus), for Fusion's values: PHP's own (ext/standard/math.c): log2() and
// log10() for those bases, a ValueError for a base of 0 or less (FunctionParityTest).
function log(arg, base = Math.E) {
    arg = P.toFloat(arg);
    base = P.toFloat(base);
    if (base === 2) return Math.log2(arg);
    if (base === 10) return Math.log10(arg);
    if (base === 1) return NaN;
    if (base <= 0) throw new P.PhpError('ValueError', 'log(): Argument #2 ($base) must be greater than 0');
    return base === Math.E ? Math.log(arg) : Math.log(arg) / Math.log(base);
}
