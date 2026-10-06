// Viewi's PhpJsFunctions/Math/Asinh.js (locutus), for Fusion's values: PHP calls the C library's asinh(), as Math.asinh() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function asinh(arg) {
    return Math.asinh(P.toFloat(arg));
}
