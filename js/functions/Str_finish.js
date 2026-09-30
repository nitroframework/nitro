// Laravel's Str::finish(): preg_replace('/(?:cap)+$/u', ...); PCRE's $ is also before a final "\n".
// uses: _pcre
function Str_finish(value, cap) {
    cap = P.toStr(cap);
    const quoted = P.fns._pcre().quote(cap);
    return P.toStr(value).replace(new RegExp('(?:' + quoted + ')+(?=\\n?$)', 'gu'), '') + cap;
}
