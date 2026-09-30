// Fusion: PHP's preg_replace() and preg_replace_callback() for one pattern and one subject (null
// where PHP fails): each match (up to limit) replaced by what make() gives for it.
// uses: _pcre_start, _pcre_each
function _pcre_replace(pattern, subject, limit, make) {
    const start = P.fns._pcre_start(pattern, subject);
    if (start === null) return null;
    const { compiled, s } = start;
    let out = '';
    let last = 0;
    P.fns._pcre_each(compiled, s.work, 0, limit, (match) => {
        out += s.piece(last, match.index) + make(compiled, s, match);
        last = match.index + match[0].length;
    });
    return out + s.piece(last, s.work.length);
}
