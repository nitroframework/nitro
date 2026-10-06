// Laravel's Str::words(): preg_match('/^\s*+(?:\S++\s*+){1,words}/u', ...) (the possessive
// quantifiers match what greedy ones do here); a count under 1 is no pattern PCRE compiles.
// uses: Str_length, _pcre
function Str_words(value, words = 100, end = '...') {
    value = P.toStr(value);
    words = P.toStr(words);
    if (!/^[1-9][0-9]*$/.test(words)) return value;
    const ws = P.fns._pcre().ws;
    const match = value.match(new RegExp('^[' + ws + ']*(?:[^' + ws + ']+[' + ws + ']*){1,' + words + '}', 'u'));
    if (!match || P.fns.Str_length(value) === P.fns.Str_length(match[0])) return value;
    return P.fns.rtrim(match[0]) + P.toStr(end);
}
