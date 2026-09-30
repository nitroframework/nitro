// Laravel's Str::title().
// uses: mb_convert_case
function Str_title(value) {
    return P.fns.mb_convert_case(value, 2);
}
