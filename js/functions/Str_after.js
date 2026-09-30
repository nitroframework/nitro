// Laravel's Str::after() (Illuminate\Support\Str, 13.x), for Fusion's values.
function Str_after(subject, search) {
    if (search === '') return subject;
    const parts = P.fns.explode(search, subject, 2);
    return parts[parts.length - 1];
}
