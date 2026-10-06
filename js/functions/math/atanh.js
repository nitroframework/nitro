// Viewi's PhpJsFunctions/Math/Atanh.js (locutus), for Fusion's values: PHP calls the C library's atanh(), as Math.atanh() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function atanh(arg) {
    return Math.atanh(P.toFloat(arg));
}
