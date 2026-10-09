// Viewi's PhpJsFunctions/Math/Sin.js (locutus), for Nitro's values: PHP calls the C library's sin(), as Math.sin() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function sin(arg) {
    return Math.sin(P.toFloat(arg));
}
