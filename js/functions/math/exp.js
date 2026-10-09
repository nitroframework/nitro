// Viewi's PhpJsFunctions/Math/Exp.js (locutus), for Nitro's values: PHP calls the C library's exp(), as Math.exp() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function exp(arg) {
    return Math.exp(P.toFloat(arg));
}
