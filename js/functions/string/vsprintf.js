// Viewi's PhpJsFunctions/Strings/Vsprintf.js (locutus), for Fusion's values.
// uses: sprintf
function vsprintf(format, args) {
    /** Fusion: the values of the PHP array (a list or a Map); PHP's TypeError for anything else. */
    if (!P.isArray(args)) throw new P.PhpError('TypeError', `vsprintf(): Argument #2 ($values) must be of type array, ${P.typeName(args)} given`);
    return P.fns.sprintf(format, ...P.entries(args).map(([, v]) => v));
}
