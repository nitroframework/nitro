// Viewi's PhpJsFunctions/Math/Tan.js (locutus), for Nitro's values: PHP calls the C library's tan(), as Math.tan() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function tan(arg) {
    return Math.tan(P.toFloat(arg));
}
