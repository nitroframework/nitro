// Laravel's Str::ucsplit().
function Str_ucsplit(string) {
    return P.toStr(string).split(/(?=\p{Lu})/u).filter((part) => part !== '');
}
