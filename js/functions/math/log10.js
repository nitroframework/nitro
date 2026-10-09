// Viewi's PhpJsFunctions/Math/Log10.js (locutus), for Nitro's values: PHP calls the C library's log10(), as Math.log10() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function log10(arg) {
    return Math.log10(P.toFloat(arg));
}
