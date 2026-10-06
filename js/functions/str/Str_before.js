// Laravel's Str::before().
// uses: strstr
function Str_before(subject, search) {
    if (search === '') return subject;
    const result = P.fns.strstr(subject, P.toStr(search), true);
    return result === false ? subject : result;
}
