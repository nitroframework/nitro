// Viewi's PhpJsFunctions/Strings/Stripslashes.js (locutus), for Fusion's values.
function stripslashes(str) {
    return P.toStr(str)
        .replace(/\\(.?)/g, function (s, n1) {
            switch (n1) {
                case '\\':
                    return '\\';
                case '0':
                    return '\u0000';
                case '':
                    return '';
                default:
                    return n1;
            }
        });
}
