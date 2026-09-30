// Viewi's PhpJsFunctions/Math/Cosh.js (locutus), for Fusion's values: PHP calls the C library's cosh(), as Math.cosh() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function cosh(arg) {
    return Math.cosh(P.toFloat(arg));
}
