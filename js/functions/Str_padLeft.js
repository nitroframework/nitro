// Laravel's Str::padLeft().
// uses: mb_str_pad
function Str_padLeft(value, length, pad = ' ') {
    return P.fns.mb_str_pad(value, length, pad, 0);
}
