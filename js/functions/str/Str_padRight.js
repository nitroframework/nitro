// Laravel's Str::padRight().
// uses: mb_str_pad
function Str_padRight(value, length, pad = ' ') {
    return P.fns.mb_str_pad(value, length, pad, 1);
}
