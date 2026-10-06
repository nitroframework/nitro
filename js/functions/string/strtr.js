// Viewi's PhpJsFunctions/Strings/Strtr.js, for Fusion's values.
function strtr(str, from, to) {
    str = P.toStr(str);
    if (to !== undefined) {
        /** Fusion: bytes, as PHP (FunctionParityTest). */
        const bytes = P.utf8(str);
        from = P.utf8(P.toStr(from));
        to = P.utf8(P.toStr(to));
        const n = Math.min(from.length, to.length);
        const map = new Map();
        for (let i = 0; i < n; i++) map.set(from[i], to[i]);
        return P.fromUtf8(bytes.map((b) => map.get(b) ?? b));
    }
    const pairs = P.entries(from)
        .map(function (entry) {
            return [P.toStr(entry[0]), P.toStr(entry[1])];
        })
        .filter(function (pair) {
            return pair[0] !== '';
        })
        .sort(function (a, b) {
            return b[0].length - a[0].length;
        });
    let out = '';
    let i = 0;
    while (i < str.length) {
        let matched = false;
        for (const pair of pairs) {
            if (str.startsWith(pair[0], i)) {
                out += pair[1];
                i += pair[0].length;
                matched = true;
                break;
            }
        }
        if (!matched) {
            out += str[i++];
        }
    }
    return out;
}
