// Laravel's Str::padBoth().
// uses: mb_str_pad
function Str_padBoth(value, length, pad = ' ') {
    return P.fns.mb_str_pad(value, length, pad, 2);
}
