// Fusion: preg_replace()'s patterns (a string or an array, with replacements to match) on a
// subject or each of an array of them (keys kept, null where PHP fails).
function _pcre_each_subject(patterns, subject, one) {
    const apply = (value) => {
        let out = P.toStr(value);
        for (const [i, pattern] of patterns.entries()) {
            out = one(pattern, out, i);
            if (out === null) return null;
        }
        return out;
    };
    if (P.isArray(subject)) return P.normalize(new Map(P.entries(subject).map(([k, v]) => [k, apply(v)])));
    return apply(subject);
}
