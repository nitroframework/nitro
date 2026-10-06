// Laravel's Str::studly().
// uses: Str_lower, Str_replace, Str_ucfirst, _pcre
function Str_studly(value, normalize = false) {
    value = P.toStr(value);
    const ws = P.fns._pcre().ws;
    if (P.bool(normalize)) {
        value = value.replace(new RegExp('(^|[-_ ' + ws + '])([A-Z]+)(?=[-_ ' + ws + ']|$)', 'gu'), (m, before, upper) => before + P.fns.Str_lower(upper));
    }
    const words = P.fns.Str_replace(['-', '_'], ' ', value).split(new RegExp('[' + ws + ']+', 'u')).filter((word) => word !== '');
    return words.map((word) => P.fns.Str_ucfirst(word)).join('');
}
