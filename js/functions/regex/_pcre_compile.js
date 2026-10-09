// Nitro: a PHP (PCRE2) pattern as a JavaScript RegExp that matches what PCRE matches. Viewi's
// _php_regex hands the pattern to RegExp as it is, which differs from PCRE in $, ., \s, \w, \b,
// \A, \z, \Z, \h, \v, \R, \x{...}, \Q...\E, POSIX classes, possessive quantifiers, atomic groups,
// named groups, the x and U modifiers and, without /u, in matching bytes (FunctionParityTest).
//
// Without /u, PCRE matches bytes: the pattern and the subject are then byte strings (a character
// per byte) and the RegExp has no "u" flag. Capture groups are named __g1, __g2, ... (their
// numbers stay PCRE's whatever the translation adds). What PCRE has and JavaScript cannot do
// faithfully (recursion, conditionals, \K, verbs, callouts, inline modifiers inside the pattern)
// is an Error; a pattern PCRE would not compile is { invalid: message } (PHP warns and fails).
function _pcre_compile(pattern) {
    const cache = (_pcre_compile.cache ??= new Map());
    pattern = P.toStr(pattern);
    if (cache.has(pattern)) return cache.get(pattern);

    const fail = (message) => {
        throw new P.PhpError('Error', `preg: ${message} is not available in the browser (pattern ${pattern})`);
    };
    const invalid = (message) => {
        const error = new Error(message);
        error.invalid = true;
        throw error;
    };

    let result;
    try {
        result = translate();
    } catch (error) {
        if (!error.invalid) throw error;
        result = { invalid: error.message };
    }
    cache.set(pattern, result);
    return result;

    function translate() {
        // ---- delimiters and modifiers
        const start = pattern.search(/\S/);
        if (start === -1) invalid('Empty regular expression');
        const open = pattern[start];
        if (/[A-Za-z0-9\\]/.test(open)) invalid('Delimiter must not be alphanumeric, backslash, or NUL');
        const close = { '(': ')', '[': ']', '{': '}', '<': '>' }[open] ?? open;
        let end = -1;
        if (close === open) {
            for (let i = start + 1; i < pattern.length; i++) {
                if (pattern[i] === '\\') i++;
                else if (pattern[i] === close) {
                    end = i;
                    break;
                }
            }
        } else {
            let depth = 1;
            for (let i = start + 1; i < pattern.length; i++) {
                if (pattern[i] === '\\') i++;
                else if (pattern[i] === open) depth++;
                else if (pattern[i] === close && --depth === 0) {
                    end = i;
                    break;
                }
            }
        }
        if (end === -1) invalid(`No ending delimiter '${close}' found`);
        let body = pattern.slice(start + 1, end);
        const modifiers = pattern.slice(end + 1);
        const mod = {};
        for (const m of modifiers) {
            if ('imsxuUDAXSJn\n\r '.indexOf(m) === -1) invalid(`Unknown modifier '${m}'`);
            mod[m] = true;
        }
        if (mod.J) fail('the J modifier (duplicate group names)');
        if (mod.n) fail('the n modifier');
        /** A leading (?flags): the pattern's modifiers (PCRE allows them anywhere; JavaScript not everywhere). */
        const leading = body.match(/^\(\?([imsxU]+)\)/);
        if (leading) {
            for (const m of leading[1]) mod[m] = true;
            body = body.slice(leading[0].length);
        }

        const utf = !!mod.u;
        /** Without /u: bytes (each a character from 0 to 255). */
        const src = utf ? [...body] : [...String.fromCharCode(...P.utf8(body))];
        const caseless = !!mod.i;
        const WS_U = '\\t\\n\\x0B\\f\\r\\x85\\u180E\\p{Z}';
        const WS_A = '\\t\\n\\x0B\\f\\r ';
        const DIGIT = utf ? '\\p{Nd}' : '0-9';
        const WORD = utf ? '\\p{L}\\p{N}\\p{Mn}\\p{Pc}' : 'A-Za-z0-9_';
        const SPACE = utf ? WS_U : WS_A;
        const H = '\\t \\xA0\\u1680\\u180E\\u2000-\\u200A\\u202F\\u205F\\u3000';
        const V = '\\n\\x0B\\f\\r\\x85\\u2028\\u2029';
        /** Class items: [content, negated]. */
        const SHORTHAND = {
            d: [DIGIT, false], D: [DIGIT, true], w: [WORD, false], W: [WORD, true], s: [SPACE, false], S: [SPACE, true],
            h: [utf ? H : '\\t \\xA0', false], H: [utf ? H : '\\t \\xA0', true],
            v: [utf ? V : '\\n\\x0B\\f\\r\\x85', false], V: [utf ? V : '\\n\\x0B\\f\\r\\x85', true],
        };
        const POSIX = {
            alpha: utf ? '\\p{L}' : 'A-Za-z', digit: utf ? '\\p{Nd}' : '0-9', alnum: utf ? '\\p{L}\\p{N}' : 'A-Za-z0-9',
            lower: utf ? '\\p{Ll}' : 'a-z', upper: utf ? '\\p{Lu}' : 'A-Z', space: SPACE, word: WORD,
            blank: utf ? H : '\\t ', cntrl: utf ? '\\p{Cc}' : '\\x00-\\x1F\\x7F', xdigit: '0-9A-Fa-f', ascii: '\\x00-\\x7F',
            punct: utf ? null : '!-\\/:-@\\[-`{-~', graph: utf ? null : '!-~', print: utf ? null : ' -~',
        };

        let out = '';
        let i = 0;
        let groups = 0;
        const names = [];
        let atomics = 0;
        /** Where each open group's text began in out, and whether it is atomic (its synthetic name). */
        const stack = [];
        /** Where the last atom began in out (for a quantifier after it), or -1. */
        let atomStart = -1;

        const peek = (n = 0) => src[i + n];
        const hex = (c) => '\\x' + c.charCodeAt(0).toString(16).padStart(2, '0');
        const literal = (c) => {
            const code = c.codePointAt(0);
            if (!utf && code > 0x7F) return hex(c);
            if (caseless && !utf && /[A-Za-z]/.test(c)) return '[' + c.toLowerCase() + c.toUpperCase() + ']';
            if ('\\^$.|?*+()[]{}/'.includes(c)) return '\\' + c;
            if (c === '\n') return '\\n';
            if (c === '\r') return '\\r';
            return code < 0x20 ? hex(c) : c;
        };
        /** A literal inside a character class. */
        const classLiteral = (c) => {
            const code = c.codePointAt(0);
            if (!utf && code > 0x7F) return hex(c);
            if ('\\]^-['.includes(c)) return '\\' + c;
            return code < 0x20 ? hex(c) : c;
        };
        const codePointChar = (code) => {
            if (!utf && code > 0xFF) invalid('Character code point value in \\x{} or \\o{} is too large');
            if (code > 0x10FFFF || (code >= 0xD800 && code <= 0xDFFF)) invalid('Character code point value in \\x{} or \\o{} is too large');
            return String.fromCodePoint(code);
        };
        const unicodeProperty = (negated, name) => {
            if (!utf) fail('\\p without the u modifier');
            if (name[0] === '^') {
                negated = !negated;
                name = name.slice(1);
            }
            const map = { Any: '[\\s\\S]', 'L&': '[\\p{Lu}\\p{Ll}\\p{Lt}]', Xan: '[\\p{L}\\p{N}]', Xsp: `[${WS_U}]`, Xps: `[${WS_U}]`, Xwd: `[${WORD}]` };
            let item;
            if (map[name]) item = map[name];
            else if (/^[A-Z][a-z]?$/.test(name)) item = `\\p{${name}}`;
            else item = `\\p{Script=${name}}`;
            try {
                new RegExp(item, 'u');
            } catch {
                invalid(`Unknown property name after \\P or \\p (${name})`);
            }
            return [item.startsWith('[') ? item.slice(1, -1) : item, negated];
        };
        /** \x.., \x{..}, \0.., \o{..}, \c., \e, \a, \n, \r, \t, \f: a character, or null. */
        const characterEscape = () => {
            const c = peek();
            if (c === 'x') {
                i++;
                if (peek() === '{') {
                    const close = src.indexOf('}', i);
                    const digits = src.slice(i + 1, close).join('');
                    if (close === -1 || !/^[0-9A-Fa-f]+$/.test(digits)) invalid('\\x{ is not followed by hexadecimal digits');
                    i = close + 1;
                    return codePointChar(parseInt(digits, 16));
                }
                let digits = '';
                while (digits.length < 2 && /[0-9A-Fa-f]/.test(peek() ?? '')) digits += src[i++];
                return String.fromCharCode(digits === '' ? 0 : parseInt(digits, 16));
            }
            if (c === 'o' && peek(1) === '{') {
                const close = src.indexOf('}', i);
                const digits = src.slice(i + 2, close).join('');
                if (close === -1 || !/^[0-7]+$/.test(digits)) invalid('\\o{ is not followed by octal digits');
                i = close + 1;
                return codePointChar(parseInt(digits, 8));
            }
            if (c === '0') {
                let digits = '';
                i++;
                while (digits.length < 2 && /[0-7]/.test(peek() ?? '')) digits += src[i++];
                return String.fromCharCode(parseInt('0' + digits, 8));
            }
            if (c === 'c') {
                const next = peek(1);
                if (next === undefined || next.charCodeAt(0) > 0x7F) invalid('\\c must be followed by a printable ASCII character');
                i += 2;
                return String.fromCharCode(next.toUpperCase().charCodeAt(0) ^ 0x40);
            }
            const simple = { e: '\x1B', a: '\x07', n: '\n', r: '\r', t: '\t', f: '\f' }[c];
            if (simple !== undefined) {
                i++;
                return simple;
            }
            return null;
        };

        // ---- character classes
        const characterClass = () => {
            i++; // [
            let negated = false;
            if (peek() === '^') {
                negated = true;
                i++;
            }
            const items = [];
            const negatedItems = [];
            let first = true;
            const rangeFrom = (c) => {
                /** a-z: a range, unless the - ends the class or what follows is no character. */
                if (peek() === '-' && peek(1) !== undefined && peek(1) !== ']') {
                    const save = i;
                    i++;
                    const to = classChar();
                    if (to !== null && typeof to === 'string') {
                        if (to.codePointAt(0) < c.codePointAt(0)) invalid('Range out of order in character class');
                        items.push(classLiteral(c) + '-' + classLiteral(to));
                        if (caseless && !utf) {
                            for (let code = c.codePointAt(0); code <= to.codePointAt(0); code++) {
                                const ch = String.fromCharCode(code);
                                if (/[A-Za-z]/.test(ch)) items.push(ch === ch.toLowerCase() ? ch.toUpperCase() : ch.toLowerCase());
                            }
                        }
                        return true;
                    }
                    i = save;
                }
                return false;
            };
            /** One class character: a string, [content, negated] for a set, or null at the end. */
            const classChar = () => {
                const c = peek();
                if (c === undefined) invalid('Missing terminating ] for character class');
                if (c === '[' && peek(1) === ':') {
                    const close = src.indexOf(']', i + 2);
                    const spec = close === -1 ? null : src.slice(i + 2, close).join('').match(/^(\^?)([a-z]+):$/);
                    if (spec) {
                        if (!(spec[2] in POSIX)) invalid('Unknown POSIX class name');
                        if (POSIX[spec[2]] === null) fail(`[:${spec[2]}:] with the u modifier`);
                        i = close + 1;
                        return [POSIX[spec[2]], spec[1] === '^'];
                    }
                }
                if (c === '\\') {
                    i++;
                    const e = peek();
                    if (e === undefined) invalid('\\ at end of pattern');
                    if (SHORTHAND[e]) {
                        i++;
                        return SHORTHAND[e];
                    }
                    if (e === 'p' || e === 'P') {
                        i++;
                        let name;
                        if (peek() === '{') {
                            const close = src.indexOf('}', i);
                            name = src.slice(i + 1, close).join('');
                            i = close + 1;
                        } else {
                            name = src[i++];
                        }
                        return unicodeProperty(e === 'P', name);
                    }
                    if (e === 'b') {
                        i++;
                        return '\b';
                    }
                    const ch = characterEscape();
                    if (ch !== null) return ch;
                    if (/[A-Za-z0-9]/.test(e)) invalid(`Unrecognized character follows \\ (${e})`);
                    i++;
                    return e;
                }
                i++;
                return c;
            };
            while (true) {
                if (peek() === undefined) invalid('Missing terminating ] for character class');
                if (peek() === ']' && !first) {
                    i++;
                    break;
                }
                const item = classChar();
                first = false;
                if (Array.isArray(item)) {
                    (item[1] ? negatedItems : items).push(item[0]);
                } else if (!rangeFrom(item)) {
                    items.push(classLiteral(item));
                    if (caseless && !utf && /[A-Za-z]/.test(item)) items.push(item === item.toLowerCase() ? item.toUpperCase() : item.toLowerCase());
                }
            }
            const positive = items.join('');
            if (negatedItems.length === 0) return negated ? `[^${positive}]` : `[${positive}]`;
            /** Negated sets in a class (\S, \W, \D, [:^alpha:]): what JavaScript's classes cannot hold. */
            if (!negated) return '(?:' + [...(positive ? [`[${positive}]`] : []), ...negatedItems.map((n) => `[^${n}]`)].join('|') + ')';
            return (positive ? `(?![${positive}])` : '') + negatedItems.slice(0, -1).map((n) => `(?=[${n}])`).join('') + `[${negatedItems.at(-1)}]`;
        };

        // ---- the pattern
        const shorthandAtom = ([content, negated]) => (negated ? `[^${content}]` : `[${content}]`);
        const boundary = (negated) => {
            const w = `[${WORD}]`;
            const at = `(?:(?<=${w})(?!${w})|(?<!${w})(?=${w}))`;
            return negated ? `(?!${at})` : at;
        };
        const quantifier = () => {
            const c = peek();
            let q = null;
            if (c === '*' || c === '+' || c === '?') {
                q = c;
                i++;
            } else if (c === '{') {
                const rest = src.slice(i).join('');
                const m = rest.match(/^\{\s*(\d*)\s*(?:(,)\s*(\d*)\s*)?\}/);
                if (m && (m[1] !== '' || (m[2] && m[3] !== ''))) {
                    const min = m[1] === '' ? '0' : m[1];
                    q = m[2] ? `{${min},${m[3]}}` : `{${min}}`;
                    if (m[3] !== undefined && m[3] !== '' && Number(m[3]) < Number(min)) invalid('numbers out of order in {} quantifier');
                    i += [...m[0]].length;
                }
            }
            if (q === null) return false;
            if (atomStart === -1) invalid('quantifier does not follow a repeatable item');
            let lazy = false;
            let possessive = false;
            if (peek() === '?') {
                lazy = true;
                i++;
            } else if (peek() === '+') {
                possessive = true;
                i++;
            }
            if (mod.U && !possessive) lazy = !lazy;
            if (possessive) {
                /** Possessive: an atomic group, (?=(?<a>X*))\k<a>. */
                const name = `__a${++atomics}`;
                out = out.slice(0, atomStart) + `(?=(?<${name}>(?:${out.slice(atomStart)})${q}))\\k<${name}>`;
            } else {
                out += q + (lazy ? '?' : '');
            }
            atomStart = -1;
            return true;
        };

        while (i < src.length) {
            const c = peek();
            if (mod.x && (c === ' ' || c === '\t' || c === '\n' || c === '\r' || c === '\f' || c === '\x0B')) {
                i++;
                continue;
            }
            if (mod.x && c === '#') {
                while (i < src.length && peek() !== '\n') i++;
                continue;
            }
            if (quantifier()) continue;
            if (c === '*' || c === '+' || c === '?') invalid('quantifier does not follow a repeatable item');
            const at = out.length;
            if (c === '\\') {
                i++;
                const e = peek();
                if (e === undefined) invalid('\\ at end of pattern');
                if (SHORTHAND[e]) {
                    i++;
                    out += shorthandAtom(SHORTHAND[e]);
                } else if (e === 'p' || e === 'P') {
                    i++;
                    let name;
                    if (peek() === '{') {
                        const close = src.indexOf('}', i);
                        if (close === -1) invalid('malformed \\P or \\p sequence');
                        name = src.slice(i + 1, close).join('');
                        i = close + 1;
                    } else {
                        name = src[i++];
                    }
                    out += shorthandAtom(unicodeProperty(e === 'P', name));
                } else if (e === 'b' || e === 'B') {
                    i++;
                    out += boundary(e === 'B');
                    atomStart = -1;
                    continue;
                } else if (e === 'A') {
                    i++;
                    out += '(?<![\\s\\S])';
                    atomStart = -1;
                    continue;
                } else if (e === 'z') {
                    i++;
                    out += '(?![\\s\\S])';
                    atomStart = -1;
                    continue;
                } else if (e === 'Z') {
                    i++;
                    out += '(?=\\n?(?![\\s\\S]))';
                    atomStart = -1;
                    continue;
                } else if (e === 'G') {
                    if (out !== '') fail('\\G not at the start of the pattern');
                    i++;
                    mod.A = true;
                    continue;
                } else if (e === 'R') {
                    i++;
                    out += utf ? '(?:\\r\\n|[\\n\\x0B\\f\\r\\x85\\u2028\\u2029])' : '(?:\\r\\n|[\\n\\x0B\\f\\r\\x85])';
                } else if (e === 'N' && peek(1) !== '{') {
                    i++;
                    out += '[^\\n]';
                } else if (e === 'Q') {
                    i++;
                    const close = src.join('').indexOf('\\E', i);
                    const quoted = src.slice(i, close === -1 ? src.length : close);
                    i = close === -1 ? src.length : close + 2;
                    if (quoted.length === 0) continue;
                    for (const q of quoted.slice(0, -1)) out += literal(q);
                    const last = out.length;
                    out += literal(quoted.at(-1));
                    atomStart = last;
                    continue;
                } else if (e === 'E') {
                    i++;
                    continue;
                } else if (/[1-9]/.test(e) || e === 'g' || e === 'k') {
                    /** Backreferences: \1, \g1, \g{1}, \g{-1}, \g{name}, \k<name>, \k'name', \k{name}. */
                    let ref;
                    if (e === 'g' || e === 'k') {
                        i++;
                        const opener = peek();
                        const closer = { '{': '}', '<': '>', "'": "'" }[opener];
                        if (closer) {
                            const close = src.indexOf(closer, i + 1);
                            if (close === -1) invalid('\\g is not followed by a braced, angle-bracketed, or quoted name/number');
                            ref = src.slice(i + 1, close).join('');
                            i = close + 1;
                        } else if (e === 'g' && /[-\d]/.test(opener ?? '')) {
                            ref = '';
                            if (peek() === '-') ref += src[i++];
                            while (/\d/.test(peek() ?? '')) ref += src[i++];
                        } else {
                            invalid('\\k is not followed by a braced, angle-bracketed, or quoted name');
                        }
                        if (e === 'g' && opener === '<' || e === 'g' && opener === "'") fail('subroutine calls (\\g<...>)');
                    } else {
                        let digits = '';
                        while (/\d/.test(peek() ?? '')) digits += src[i++];
                        if (Number(digits) >= 10 && Number(digits) > groups && !/^[89]/.test(digits)) {
                            /** Octal, as PCRE reads \10 and up with fewer groups before it. */
                            i -= digits.length - 1;
                            i--;
                            let octal = '';
                            while (octal.length < 3 && /[0-7]/.test(peek() ?? '')) octal += src[i++];
                            out += literal(String.fromCharCode(parseInt(octal, 8)));
                            atomStart = at;
                            continue;
                        }
                        ref = digits;
                    }
                    let number;
                    if (/^-\d+$/.test(ref)) number = groups + 1 + Number(ref);
                    else if (/^\d+$/.test(ref)) number = Number(ref);
                    else number = names.find(([name]) => name === ref)?.[1];
                    if (!number || number < 1) invalid('reference to non-existent subpattern');
                    if (caseless && !utf) fail('a backreference with the i modifier without the u modifier');
                    out += `\\k<__g${number}>`;
                } else {
                    const ch = characterEscape();
                    if (ch !== null) {
                        out += literal(ch);
                    } else {
                        if (/[A-Za-z0-9]/.test(e)) {
                            if ('KCXL lNuU'.includes(e)) fail(`\\${e}`);
                            invalid(`Unrecognized character follows \\ (${e})`);
                        }
                        i++;
                        out += literal(e);
                    }
                }
                atomStart = at;
                continue;
            }
            if (c === '[') {
                out += characterClass();
                atomStart = at;
                continue;
            }
            if (c === '(') {
                i++;
                let name = null;
                let atomic = false;
                let head = '(';
                if (peek() === '*') fail('backtracking control verbs (*...)');
                if (peek() === '?') {
                    const rest = src.slice(i).join('');
                    let m;
                    if ((m = rest.match(/^\?(?:P?<([A-Za-z_]\w*)>|'([A-Za-z_]\w*)')/))) {
                        name = m[1] ?? m[2];
                        i += m[0].length;
                    } else if ((m = rest.match(/^\?P=([A-Za-z_]\w*)\)/))) {
                        const number = names.find(([n]) => n === m[1])?.[1];
                        if (!number) invalid('reference to non-existent subpattern');
                        i += m[0].length;
                        out += `\\k<__g${number}>`;
                        atomStart = at;
                        continue;
                    } else if (rest.startsWith('?:')) {
                        head = '(?:';
                        i += 2;
                    } else if (rest.startsWith('?>')) {
                        atomic = true;
                        head = '(?:';
                        i += 2;
                    } else if ((m = rest.match(/^\?(=|!|<=|<!)/))) {
                        head = '(' + m[0];
                        i += m[0].length;
                    } else if (rest.startsWith('?#')) {
                        const close = src.indexOf(')', i);
                        if (close === -1) invalid('missing ) after (?# comment');
                        i = close + 1;
                        continue;
                    } else if (rest.startsWith('?|')) {
                        fail('(?| duplicate group numbers');
                    } else if (/^\?[imsxU-]+[:)]/.test(rest)) {
                        fail('inline modifiers inside the pattern (put them after the delimiter)');
                    } else if (/^\?(R|\d|[+-]\d|&|P>|\()/.test(rest)) {
                        fail('recursion, subroutine calls and conditional groups');
                    } else if (/^\?C/.test(rest)) {
                        fail('callouts');
                    } else {
                        invalid('unrecognized character after (? or (?-');
                    }
                }
                if (head === '(') {
                    groups++;
                    if (name !== null) {
                        if (names.some(([n]) => n === name)) invalid('two named subpatterns have the same name (PCRE2_DUPNAMES not set)');
                        names.push([name, groups]);
                    }
                    head = `(?<__g${groups}>`;
                }
                stack.push({ at: out.length, atomic });
                out += head;
                atomStart = -1;
                continue;
            }
            if (c === ')') {
                i++;
                const group = stack.pop();
                if (!group) invalid('unmatched closing parenthesis');
                out += ')';
                if (group.atomic) {
                    const name = `__a${++atomics}`;
                    out = out.slice(0, group.at) + `(?=(?<${name}>${out.slice(group.at)}))\\k<${name}>`;
                }
                atomStart = group.at;
                continue;
            }
            if (c === '|') {
                i++;
                out += '|';
                atomStart = -1;
                continue;
            }
            if (c === '^') {
                i++;
                out += mod.m ? '(?<![^\\n])' : '(?<![\\s\\S])';
                atomStart = -1;
                continue;
            }
            if (c === '$') {
                i++;
                out += mod.m ? '(?![^\\n])' : mod.D ? '(?![\\s\\S])' : '(?=\\n?(?![\\s\\S]))';
                atomStart = -1;
                continue;
            }
            if (c === '.') {
                i++;
                out += mod.s ? '[\\s\\S]' : '[^\\n]';
                atomStart = at;
                continue;
            }
            i++;
            out += literal(c);
            atomStart = at;
        }
        if (stack.length) invalid('missing closing parenthesis');

        const flags = (utf ? 'u' : '') + (caseless && utf ? 'i' : '') + 'g';
        let regex;
        try {
            regex = new RegExp(out, flags);
        } catch (error) {
            fail(`this pattern (${error.message})`);
        }
        return { regex, source: out, flags, utf, groups, names, anchored: !!mod.A };
    }
}
