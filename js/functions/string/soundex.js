// Viewi's PhpJsFunctions/Strings/Soundex.js (locutus) codes a digit or other character first
// (FunctionParityTest): Nitro ports PHP's own (ext/standard/soundex.c), on bytes.
function soundex(str) {
    /* A  B  C  D  E  F  G  H  I  J  K  L  M  N  O  P  Q  R  S  T  U  V  W  X  Y  Z */
    const table = [0, '1', '2', '3', 0, '1', '2', 0, 0, '2', '2', '4', '5', '5', 0, '1', '2', '6', '2', '3', 0, '1', 0, '2', 0, '2'];
    const bytes = P.utf8(P.toStr(str));
    const soundex = [];
    let last = -1;
    for (let i = 0; i < bytes.length && soundex.length < 4; i++) {
        let code = bytes[i];
        if (code >= 97 && code <= 122) code -= 32;
        if (code >= 65 && code <= 90) {
            if (soundex.length === 0) {
                /* remember first valid char */
                soundex.push(String.fromCharCode(code));
                last = table[code - 65];
            } else {
                code = table[code - 65];
                if (code !== last) {
                    if (code !== 0) {
                        soundex.push(code);
                    }
                    last = code;
                }
            }
        }
    }
    /* pad with '0' and terminate with 0 ;-) */
    while (soundex.length < 4) {
        soundex.push('0');
    }
    return soundex.join('');
}
