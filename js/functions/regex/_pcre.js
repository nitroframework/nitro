// Nitro: what Laravel's PCRE patterns mean, for JavaScript's regular expressions ("u" flag):
// ws, PCRE's \s with /u (Unicode white space, as \p{Z}, \h and \v), for use in a character
// class; quote(s), a string matched as it is; classQuote(s), the same in a character class.
function _pcre() {
    return {
        ws: '\\t\\n\\x0B\\f\\r\\x85\\u180E\\p{Z}',
        quote: (s) => s.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&'),
        classQuote: (s) => s.replace(/[\]\\^[-]/g, '\\$&'),
    };
}
