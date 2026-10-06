// Laravel's Str::wordCount().
// uses: str_word_count
function Str_wordCount(string, characters = null) {
    return P.fns.str_word_count(string, 0, characters);
}
