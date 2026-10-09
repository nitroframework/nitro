// Viewi's PhpJsFunctions/Math/Sinh.js (locutus), for Nitro's values: PHP calls the C library's sinh(), as Math.sinh() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function sinh(arg) {
    return Math.sinh(P.toFloat(arg));
}
