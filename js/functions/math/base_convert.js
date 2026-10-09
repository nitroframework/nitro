// Viewi's PhpJsFunctions/Math/BaseConvert.js (locutus), for Nitro's values: PHP's own (_php_math_basetozval,
// _php_math_zvaltobase): digits the base does not have are skipped, so "-15" is 15 and "" 0
// (FunctionParityTest).
function base_convert(number, frombase, tobase) {
    frombase = P.toInt(frombase);
    tobase = P.toInt(tobase);
    if (frombase < 2 || frombase > 36) throw new P.PhpError('ValueError', 'base_convert(): Argument #2 ($from_base) must be between 2 and 36 (inclusive)');
    if (tobase < 2 || tobase > 36) throw new P.PhpError('ValueError', 'base_convert(): Argument #3 ($to_base) must be between 2 and 36 (inclusive)');
    let value = 0;
    for (const ch of P.toStr(number).toLowerCase()) {
        const digit = parseInt(ch, 36);
        if (Number.isNaN(digit) || digit >= frombase) continue;
        value = value * frombase + digit;
    }
    return Math.trunc(value).toString(tobase);
}
