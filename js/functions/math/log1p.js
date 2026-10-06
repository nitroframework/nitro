// Viewi's PhpJsFunctions/Math/Log1p.js (locutus), for Fusion's values: PHP calls the C library's log1p(), as Math.log1p() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function log1p(arg) {
    return Math.log1p(P.toFloat(arg));
}
