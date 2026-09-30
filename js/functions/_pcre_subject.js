// Fusion: a subject as a compiled pattern matches it (without /u: a byte string, a character per
// byte), and back: piece(from, to) as PHP's string, offset(index) in bytes, index(bytes) where
// that byte offset is (null in the middle of a character), next(index) one character on.
function _pcre_subject(compiled, subject) {
    subject = P.toStr(subject);
    if (!compiled.utf) {
        const work = String.fromCharCode(...P.utf8(subject));
        return {
            work,
            piece: (from, to) => P.fromUtf8(Uint8Array.from(work.slice(from, to), (c) => c.charCodeAt(0))),
            offset: (index) => index,
            index: (bytes) => bytes,
            next: (index) => index + 1,
        };
    }
    const starts = [];
    let bytes = 0;
    for (let index = 0; index < subject.length;) {
        starts.push([bytes, index]);
        const code = subject.codePointAt(index);
        bytes += code < 0x80 ? 1 : code < 0x800 ? 2 : code < 0x10000 ? 3 : 4;
        index += code > 0xFFFF ? 2 : 1;
    }
    starts.push([bytes, subject.length]);
    const byIndex = new Map(starts.map(([b, i]) => [i, b]));
    const byBytes = new Map(starts);
    return {
        work: subject,
        piece: (from, to) => subject.slice(from, to),
        offset: (index) => byIndex.get(index),
        index: (b) => byBytes.get(b) ?? null,
        next: (index) => index + (subject.codePointAt(index) > 0xFFFF ? 2 : 1),
    };
}
