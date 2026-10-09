// Viewi's PhpJsFunctions/Math/Expm1.js (locutus), for Nitro's values: PHP calls the C library's expm1(), as Math.expm1() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function expm1(arg) {
    return Math.expm1(P.toFloat(arg));
}
