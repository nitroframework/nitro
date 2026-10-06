/** Rounding, number_format() and sprintf(), as PHP has them. Part of php.js. */
import { PhpError } from './core.js';
import { toFloat, toInt, toStr } from './conversions.js';
import { e } from './operators.js';

// ---------------------------------------------------------------- rounding and number formatting

/** round() as PHP 8.4+: the value is rounded as the decimal it prints as, halves away from zero. */
export function round(v, precision = 0) {
    v = toFloat(v);
    if (!Number.isFinite(v)) return v;
    precision = toInt(precision);
    const [digits, exp] = decimal(Math.abs(v));
    /** v = 0.<digits> x 10^(exp + 1); keep the digits before the rounding position. */
    const kept = exp + 1 + precision;
    if (kept < 0) return v < 0 ? -0 : 0;
    if (kept >= digits.length) return v;
    let head = digits.slice(0, kept) || '0';
    if (digits.charCodeAt(kept) >= 53) head = incrementDigits(head); // next digit >= '5'
    const result = Number(`${head}e${exp + 1 - kept}`);
    return v < 0 ? -result : result;
}

/** Shortest round-trip decimal: [significant digits, exponent of the first digit]. */
function decimal(v) {
    if (v === 0) return ['0', 0];
    const [m, e] = v.toExponential().split('e');
    return [m.replace('.', ''), Number(e)];
}

function incrementDigits(s) {
    const d = s.split('').map(Number);
    let i = d.length - 1;
    while (i >= 0) {
        if (d[i] < 9) { d[i]++; return d.join(''); }
        d[i] = 0;
        i--;
    }
    return '1' + d.join('');
}

/** round() to a fixed-point string with exactly $decimals digits. */
function toFixedPhp(v, decimals) {
    const r = round(v, decimals);
    const [digits, exp] = decimal(Math.abs(r));
    let intPart;
    let frac;
    if (r === 0) {
        intPart = '0';
        frac = '';
    } else if (exp >= 0) {
        const all = digits.padEnd(exp + 1, '0');
        intPart = all.slice(0, exp + 1);
        frac = all.slice(exp + 1);
    } else {
        intPart = '0';
        frac = '0'.repeat(-exp - 1) + digits;
    }
    frac = frac.padEnd(decimals, '0').slice(0, decimals);
    return [r < 0 ? '-' : '', intPart, frac];
}

export function number_format(num, decimals = 0, decPoint = '.', thousands = ',') {
    decimals = Math.max(0, toInt(decimals));
    let [signPart, intPart, frac] = toFixedPhp(toFloat(num), decimals);
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, toStr(thousands));
    const out = intPart + (decimals > 0 ? toStr(decPoint) + frac : '');
    return (signPart && /[1-9]/.test(intPart + frac) ? '-' : '') + out;
}

// ---------------------------------------------------------------- sprintf

export function sprintf(format, ...args) {
    format = toStr(format);
    let argIndex = 0;
    return format.replace(/%(?:(\d+)\$)?([-+ 0]|'.)*(\d+)?(?:\.(\d+))?([bcdeEfFgGosuxX%])/g, (match, argnum, _f, width, precision, conv) => {
        if (conv === '%') return '%';
        const flagsPart = match.slice(1 + (argnum ? argnum.length + 1 : 0)).match(/^([-+ 0]|'.)*/)[0];
        let pad = ' ';
        let left = false;
        let plus = false;
        for (let i = 0; i < flagsPart.length; i++) {
            const f = flagsPart[i];
            if (f === '-') left = true;
            else if (f === '+') plus = true;
            else if (f === '0') pad = '0';
            else if (f === ' ') pad = ' ';
            else if (f === "'") { pad = flagsPart[i + 1]; i++; }
        }
        const idx = argnum ? Number(argnum) - 1 : argIndex++;
        if (idx >= args.length) throw new PhpError('ArgumentCountError', `${args.length + 1} arguments are required, ${idx + 2} given`);
        const arg = args[idx];
        let s;
        switch (conv) {
            case 'd': {
                const n = toInt(arg);
                s = (n < 0 ? '-' : plus ? '+' : '') + String(Math.abs(n));
                break;
            }
            case 'u': {
                const n = toInt(arg);
                s = n < 0 ? (BigInt(n) + 18446744073709551616n).toString() : String(n);
                break;
            }
            case 'f':
            case 'F': {
                /** printf rounds the exact binary value (1.005 -> "1.00"), unlike round(). */
                const p = precision === undefined ? 6 : Number(precision);
                const f = toFloat(arg);
                s = Math.abs(f) < 1e21 ? f.toFixed(p) : toFixedPhp(f, p).join('.');
                if (plus && f >= 0) s = '+' + s;
                break;
            }
            case 'e':
            case 'E': {
                const p = precision === undefined ? 6 : Number(precision);
                let [m, x] = toFloat(arg).toExponential(p).split('e');
                s = (plus && !m.startsWith('-') ? '+' : '') + m + (conv === 'E' ? 'E' : 'e') + (x.startsWith('-') ? '-' : '+') + x.replace(/^[+-]/, '');
                break;
            }
            case 's': {
                s = toStr(arg);
                if (precision !== undefined) s = s.slice(0, Number(precision));
                break;
            }
            case 'x': s = (toInt(arg) >>> 0).toString(16); break;
            case 'X': s = (toInt(arg) >>> 0).toString(16).toUpperCase(); break;
            case 'o': s = (toInt(arg) >>> 0).toString(8); break;
            case 'b': s = (toInt(arg) >>> 0).toString(2); break;
            case 'c': s = String.fromCharCode(toInt(arg)); break;
            default: s = toStr(arg);
        }
        if (width !== undefined && s.length < Number(width)) {
            const n = Number(width) - s.length;
            if (left) s = s + (pad === '0' ? ' ' : pad).repeat(n);
            else if (pad === '0' && /^[+-]/.test(s) && 'dfFeE'.includes(conv)) s = s[0] + '0'.repeat(n) + s.slice(1);
            else s = pad.repeat(n) + s;
        }
        return s;
    });
}
