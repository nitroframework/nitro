// Laravel's Str::trim(), ltrim() and rtrim() without a character list: white space, the
// invisible characters (Str::INVISIBLE_CHARACTERS) and PHP trim()'s.
// uses: _pcre
function _str_trim(value, left, right) {
    const invisible = '\\u{0009}\\u{0020}\\u{00A0}\\u{00AD}\\u{034F}\\u{061C}\\u{115F}\\u{1160}\\u{17B4}\\u{17B5}\\u{180E}\\u{2000}\\u{2001}\\u{2002}\\u{2003}\\u{2004}\\u{2005}\\u{2006}\\u{2007}\\u{2008}\\u{2009}\\u{200A}\\u{200B}\\u{200C}\\u{200D}\\u{200E}\\u{200F}\\u{202F}\\u{205F}\\u{2060}\\u{2061}\\u{2062}\\u{2063}\\u{2064}\\u{2065}\\u{206A}\\u{206B}\\u{206C}\\u{206D}\\u{206E}\\u{206F}\\u{3000}\\u{2800}\\u{3164}\\u{FEFF}\\u{FFA0}\\u{1D159}\\u{1D173}\\u{1D174}\\u{1D175}\\u{1D176}\\u{1D177}\\u{1D178}\\u{1D179}\\u{1D17A}\\u{E0020}';
    const w = '[' + P.fns._pcre().ws + invisible + ' \\n\\r\\t\\x0B\\x00]';
    value = P.toStr(value);
    if (left) value = value.replace(new RegExp('^' + w + '+', 'u'), '');
    if (right) value = value.replace(new RegExp(w + '(?<!' + w + w + ')' + w + '*$', 'u'), '');
    return value;
}
