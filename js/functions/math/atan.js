// Viewi's PhpJsFunctions/Math/Atan.js (locutus), for Fusion's values: PHP calls the C library's atan(), as Math.atan() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function atan(arg) {
    return Math.atan(P.toFloat(arg));
}
