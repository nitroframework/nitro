// Laravel's Str::start().
// uses: _pcre
function Str_start(value, prefix) {
    prefix = P.toStr(prefix);
    const quoted = P.fns._pcre().quote(prefix);
    return prefix + P.toStr(value).replace(new RegExp('^(?:' + quoted + ')+', 'u'), '');
}
