// Fusion: PHP's preg_replace_callback(): the callback gets the match as preg_match() gives it.
// uses: _pcre_replace, _pcre_each_subject, _pcre_groups
function preg_replace_callback(pattern, callback, subject, limit = -1) {
    limit = P.toInt(limit);
    callback = P.callable(callback);
    const patterns = P.isArray(pattern) ? P.entries(pattern).map(([, v]) => v) : [pattern];
    return P.fns._pcre_each_subject(patterns, subject, (one, value) =>
        P.fns._pcre_replace(one, value, limit, (compiled, s, match) => P.toStr(callback(P.fns._pcre_groups(compiled, s, match, 0, true)))));
}
