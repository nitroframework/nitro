/**
 * A typed value from a URL, as the server reads it (Support/UrlValues.php): the rules are
 * js/protocol.json's, which both sides take, so a URL means the same on both.
 */
import protocol from './protocol.js';

const rules = protocol.urlValues;
const INT = new RegExp(rules.int);
const FLOAT = new RegExp(rules.float);

/** The value of the type the text is; undefined when it is not one. */
export function readUrlValue(type, raw) {
    if (typeof raw !== 'string') return undefined;
    if (type === 'int') return INT.test(raw) && Math.abs(Number(raw)) <= rules.maxInt ? Number(raw) : undefined;
    if (type === 'float') return FLOAT.test(raw) && Number.isFinite(Number(raw)) ? Number(raw) : undefined;
    if (type === 'bool') {
        if (rules.true.includes(raw.toLowerCase())) return true;
        if (rules.false.includes(raw.toLowerCase())) return false;
        return undefined;
    }
    return raw;
}
