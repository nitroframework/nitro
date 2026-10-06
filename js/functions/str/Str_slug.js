// Laravel's Str::slug(). Fusion: transliterated as Str::ascii() does for "en" (the language
// the browser has).
// uses: Str_ascii, Str_lower, _pcre
function Str_slug(title, separator = '-', language = 'en', dictionary = new Map([['@', 'at']])) {
    const { ws, classQuote } = P.fns._pcre();
    title = P.bool(language) ? P.fns.Str_ascii(title, language) : P.toStr(title);
    separator = P.toStr(separator);
    // Convert all dashes/underscores into separator
    const flip = separator === '-' ? '_' : '-';
    title = title.replace(new RegExp('[' + classQuote(flip) + ']+', 'gu'), () => separator);
    // Replace dictionary words
    const words = P.entries(dictionary);
    title = P.fns.str_replace(words.map(([k]) => P.toStr(k)), words.map(([, v]) => separator + P.toStr(v) + separator), title);
    // Remove all characters that are not the separator, letters, numbers, or whitespace
    title = P.fns.Str_lower(title).replace(new RegExp('[^' + classQuote(separator) + '\\p{L}\\p{N}' + ws + ']+', 'gu'), '');
    // Replace all separator characters and whitespace by a single separator
    title = title.replace(new RegExp('[' + classQuote(separator) + ws + ']+', 'gu'), () => separator);
    return P.fns.trim(title, separator);
}
