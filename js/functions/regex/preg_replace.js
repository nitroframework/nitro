// Nitro: PHP's preg_replace(): $1, \1 and ${1} (one or two digits) in the replacement; a
// backslash before \ or $ makes it literal (preg_do_repl's walk).
// uses: _pcre_replace, _pcre_each_subject
function preg_replace(pattern, replacement, subject, limit = -1) {
    limit = P.toInt(limit);
    const patterns = P.isArray(pattern) ? P.entries(pattern).map(([, v]) => v) : [pattern];
    if (!P.isArray(pattern) && P.isArray(replacement)) {
        throw new P.PhpError('TypeError', 'preg_replace(): Argument #1 ($pattern) must be of type array when argument #2 ($replacement) is an array, string given');
    }
    const replacements = P.isArray(replacement) ? P.entries(replacement).map(([, v]) => P.toStr(v)) : null;
    return P.fns._pcre_each_subject(patterns, subject, (one, value, i) => {
        const template = replacements ? replacements[i] ?? '' : P.toStr(replacement);
        return P.fns._pcre_replace(one, value, limit, (compiled, s, match) => {
            const group = (k) => {
                if (k > compiled.groups) return '';
                const at = k === 0 ? [match.index, match.index + match[0].length] : match.indices.groups?.[`__g${k}`];
                return at === undefined ? '' : s.piece(at[0], at[1]);
            };
            let out = '';
            let walkLast = '';
            for (let w = 0; w < template.length;) {
                const c = template[w];
                if (c === '\\' || c === '$') {
                    if (walkLast === '\\') {
                        out = out.slice(0, -1) + c;
                        w++;
                        walkLast = '';
                        continue;
                    }
                    const ref = template.slice(w).match(c === '$' ? /^\$(?:\{(\d\d?)\}|(\d\d?))/ : /^\\(\d\d?)/);
                    if (ref) {
                        out += group(Number(ref[1] ?? ref[2]));
                        w += ref[0].length;
                        continue;
                    }
                }
                out += c;
                walkLast = c;
                w++;
            }
            return out;
        });
    });
}
