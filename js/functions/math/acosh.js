// Viewi's PhpJsFunctions/Math/Acosh.js (locutus), for Nitro's values: PHP calls the C library's acosh(), as Math.acosh() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function acosh(arg) {
    return Math.acosh(P.toFloat(arg));
}
