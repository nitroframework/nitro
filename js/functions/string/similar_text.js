// Viewi's PhpJsFunctions/Strings/SimilarText.js (locutus), for Fusion's values.
function similar_text(first, second) {
    /**
     * Fusion: bytes, as PHP. PHP's third parameter takes the percentage by reference; Viewi's
     * returns it instead of the count, which PHP code does not expect: it is not taken.
     */
    first = P.utf8(P.toStr(first));
    second = P.utf8(P.toStr(second));

    const similar = function (first, second) {
        let pos1 = 0;
        let pos2 = 0;
        let max = 0;
        const firstLength = first.length;
        const secondLength = second.length;
        let p;
        let q;
        let l;
        let sum;

        for (p = 0; p < firstLength; p++) {
            for (q = 0; q < secondLength; q++) {
                for (l = 0; (p + l < firstLength) && (q + l < secondLength) && (first[p + l] === second[q + l]); l++) { // eslint-disable-line max-len
                }
                if (l > max) {
                    max = l;
                    pos1 = p;
                    pos2 = q;
                }
            }
        }

        sum = max;

        if (sum) {
            if (pos1 && pos2) {
                sum += similar(first.subarray(0, pos1), second.subarray(0, pos2));
            }

            if ((pos1 + max < firstLength) && (pos2 + max < secondLength)) {
                sum += similar(first.subarray(pos1 + max), second.subarray(pos2 + max));
            }
        }

        return sum;
    };

    return similar(first, second);
}
