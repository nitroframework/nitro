/**
 * Merge props ($this->merge(), $this->deepMerge(), $this->scroll() in mount()): a partial reload's value
 * merged into the one the page has, by the rule it came with. Mirrors Merge::apply() in PHP, on
 * PHP values as the runtime holds them (lists are Arrays, other arrays Maps).
 */
import * as P from '../php.js';

/** @param {{mode: string, path: ?string, match: string[]}} rule */
export function merge(current, value, rule) {
    const match = P.entries(rule.match ?? []).map(([, key]) => key);

    if (rule.mode === 'deep') return deep(current, value, match);

    const prepend = rule.mode === 'prepend';
    if (rule.path === null || rule.path === undefined) return lists(current, value, prepend, match);
    if (!(value instanceof Map)) return value;

    const path = String(rule.path).split('.');
    return setIn(value, path, lists(getIn(current, path), getIn(value, path), prepend, match));
}

function lists(current, value, prepend, match) {
    if (!Array.isArray(current) || !Array.isArray(value)) return value;
    let added = value;
    let merged = [...current];

    if (match.length > 0) {
        const at = new Map();
        current.forEach((item, index) => {
            const key = matchKey(item, match);
            if (key !== null) at.set(key, index);
        });
        added = [];
        for (const item of value) {
            const key = matchKey(item, match);
            if (key !== null && at.has(key)) merged[at.get(key)] = item;
            else added.push(item);
        }
    }

    return prepend ? [...added, ...merged] : [...merged, ...added];
}

function deep(current, value, match) {
    if (Array.isArray(current) && Array.isArray(value)) return lists(current, value, false, match);
    if (!(current instanceof Map) || !(value instanceof Map)) return value;
    const merged = new Map(current);
    for (const [key, item] of value) merged.set(key, deep(current.get(key) ?? null, item, match));
    return merged;
}

/** An item's identity for matchOn: its values for the keys (null when it lacks one). */
function matchKey(item, match) {
    if (!(item instanceof Map)) return null;
    const values = [];
    for (const key of match) {
        if (!item.has(key)) return null;
        values.push(item.get(key));
    }
    return JSON.stringify(P.encode(values));
}

function getIn(value, path) {
    for (const key of path) {
        if (!(value instanceof Map) || !value.has(key)) return null;
        value = value.get(key);
    }
    return value;
}

function setIn(value, [key, ...rest], item) {
    const map = new Map(value instanceof Map ? value : []);
    map.set(key, rest.length === 0 ? item : setIn(map.get(key), rest, item));
    return map;
}
