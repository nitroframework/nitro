// Nitro: the next match from index (anchored there with the A modifier or \G); notEmpty: PCRE's
// PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED, which PHP asks for after an empty match (a match at
// index that is not empty, JavaScript's regular expression made to end elsewhere).
function _pcre_exec(compiled, work, index, notEmpty = false) {
    let regex;
    if (notEmpty) {
        const before = compiled.utf ? [...work.slice(0, index)].length : index;
        regex = new RegExp(`(?:${compiled.source})(?<!^[\\s\\S]{${before}})`, compiled.flags.replace('g', 'y') + 'd');
    } else {
        regex = compiled.anchored
            ? (compiled.sticky ??= new RegExp(compiled.source, compiled.flags.replace('g', 'y') + 'd'))
            : (compiled.global ??= new RegExp(compiled.source, compiled.flags + 'd'));
    }
    regex.lastIndex = index;
    return regex.exec(work);
}
