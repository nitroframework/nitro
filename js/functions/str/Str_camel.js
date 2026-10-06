// Laravel's Str::camel().
// uses: Str_lcfirst, Str_studly
function Str_camel(value) {
    return P.fns.Str_lcfirst(P.fns.Str_studly(value));
}
