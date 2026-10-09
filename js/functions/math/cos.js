// Viewi's PhpJsFunctions/Math/Cos.js (locutus), for Nitro's values: PHP calls the C library's cos(), as Math.cos() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function cos(arg) {
    return Math.cos(P.toFloat(arg));
}
