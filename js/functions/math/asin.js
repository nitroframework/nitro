// Viewi's PhpJsFunctions/Math/Asin.js (locutus), for Nitro's values: PHP calls the C library's asin(), as Math.asin() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function asin(arg) {
    return Math.asin(P.toFloat(arg));
}
