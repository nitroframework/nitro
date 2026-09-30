// Laravel's Str::snake(): PCRE's . (with /u) is any character but "\n".
// uses: ctype_lower, Str_lower, _pcre
function Str_snake(value, delimiter = '_') {
    value = P.toStr(value);
    delimiter = P.toStr(delimiter);
    if (!P.fns.ctype_lower(value)) {
        value = P.fns.ucwords(value).replace(new RegExp('[' + P.fns._pcre().ws + ']+', 'gu'), '');
        value = P.fns.Str_lower(value.replace(/([^\n])(?=[A-Z])/gu, (m, c) => c + delimiter));
    }
    return value;
}
