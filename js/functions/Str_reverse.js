// Laravel's Str::reverse().
// uses: mb_str_split
function Str_reverse(value) {
    return P.fns.mb_str_split(value).reverse().join('');
}
