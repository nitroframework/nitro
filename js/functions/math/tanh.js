// Viewi's PhpJsFunctions/Math/Tanh.js (locutus), for Nitro's values: PHP calls the C library's tanh(), as Math.tanh() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function tanh(arg) {
    return Math.tanh(P.toFloat(arg));
}
