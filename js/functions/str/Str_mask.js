// Laravel's Str::mask().
function Str_mask(string, character, index, length = null) {
    string = P.toStr(string);
    character = P.toStr(character);
    index = P.toInt(index);
    if (character === '') return string;
    const segment = P.fns.mb_substr(string, index, length);
    if (segment === '') return string;
    const strlen = P.fns.mb_strlen(string);
    let startIndex = index;
    if (index < 0) startIndex = index < -strlen ? 0 : strlen + index;
    const start = P.fns.mb_substr(string, 0, startIndex);
    const segmentLen = P.fns.mb_strlen(segment);
    const end = P.fns.mb_substr(string, startIndex + segmentLen);
    return start + P.fns.str_repeat(P.fns.mb_substr(character, 0, 1), segmentLen) + end;
}
