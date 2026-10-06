// Laravel's Str::kebab().
// uses: Str_snake
function Str_kebab(value) {
    return P.fns.Str_snake(value, '-');
}
