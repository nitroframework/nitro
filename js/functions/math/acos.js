// Viewi's PhpJsFunctions/Math/Acos.js (locutus), for Fusion's values: PHP calls the C library's acos(), as Math.acos() does
// (locutus works it out with a formula, a last digit off: FunctionParityTest).
function acos(arg) {
    return Math.acos(P.toFloat(arg));
}
