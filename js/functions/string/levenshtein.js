// Viewi's PhpJsFunctions/Strings/Levenshtein.js (locutus), for Fusion's values.
function levenshtein(s1, s2, costIns = 1, costRep = 1, costDel = 1) {
    costIns = P.toInt(costIns);
    costRep = P.toInt(costRep);
    costDel = P.toInt(costDel);
    /** Fusion: bytes, as PHP (FunctionParityTest). */
    s1 = P.utf8(P.toStr(s1));
    s2 = P.utf8(P.toStr(s2));

    const l1 = s1.length;
    const l2 = s2.length;

    if (l1 === 0) {
        return l2 * costIns;
    }
    if (l2 === 0) {
        return l1 * costDel;
    }

    let p1 = new Array(l2 + 1);
    let p2 = new Array(l2 + 1);

    let i1, i2, c0, c1, c2, tmp;

    for (i2 = 0; i2 <= l2; i2++) {
        p1[i2] = i2 * costIns;
    }

    for (i1 = 0; i1 < l1; i1++) {
        p2[0] = p1[0] + costDel;

        for (i2 = 0; i2 < l2; i2++) {
            c0 = p1[i2] + ((s1[i1] === s2[i2]) ? 0 : costRep);
            c1 = p1[i2 + 1] + costDel;
            if (c1 < c0) {
                c0 = c1;
            }
            c2 = p2[i2] + costIns;
            if (c2 < c0) {
                c0 = c2;
            }
            p2[i2 + 1] = c0;
        }

        tmp = p1;
        p1 = p2;
        p2 = tmp;
    }

    c0 = p1[l2];

    return c0;
}
