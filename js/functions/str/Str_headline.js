// Laravel's Str::headline().
// uses: Str_title, Str_ucsplit, Str_replace, _pcre
function Str_headline(value) {
    let parts = P.toStr(value).split(new RegExp('[' + P.fns._pcre().ws + ']+', 'u')).filter((part) => part !== '');
    parts = parts.length > 1
        ? parts.map((part) => P.fns.Str_title(part))
        : P.fns.Str_ucsplit(parts.join('_')).map((part) => P.fns.Str_title(part));
    const collapsed = P.fns.Str_replace(['-', '_', ' '], '_', parts.join('_'));
    return collapsed.split('_').filter((part) => P.bool(part)).join(' ');
}
