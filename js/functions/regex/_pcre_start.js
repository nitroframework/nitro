// Nitro: a pattern compiled, the subject for it, and where matching starts (PHP's offset in
// bytes, negative from the end); null where PHP fails (a pattern it does not compile, an offset
// in the middle of a character).
// uses: _pcre_compile, _pcre_subject
function _pcre_start(pattern, subject, offset = 0) {
    const compiled = P.fns._pcre_compile(pattern);
    if (compiled.invalid !== undefined) return null;
    const s = P.fns._pcre_subject(compiled, subject);
    offset = P.toInt(offset);
    const bytes = s.offset(s.work.length);
    if (offset < 0) offset = Math.max(0, bytes + offset);
    if (offset > bytes) return { compiled, s, index: null };
    const index = s.index(offset);
    return { compiled, s, index };
}
