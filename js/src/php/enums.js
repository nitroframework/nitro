/** #[Browser] enums: one object per case. Part of php.js. */
import { PhpError, typeError } from './core.js';
import { toStr, typeName } from './conversions.js';

// ---------------------------------------------------------------- enums

/**
 * A case of a #[Browser] enum: one frozen object per case, so === and match compare cases as PHP
 * does. It has ->name, a backed enum's ->value, and the enum's compiled methods (its prototype's).
 */
export class EnumCase {}

/**
 * A #[Browser] enum, as Fusion.classes holds it: its cases by name ($cases), cases(), from() and
 * tryFrom() for a backed one, and its compiled static methods. $class names it in errors.
 *
 * @param cases [[name, value], ...], or [[name], ...] for a pure enum
 */
export function defineEnum($class, cases, statics = {}, methods = {}) {
    const proto = Object.create(EnumCase.prototype);
    Object.defineProperty(proto, '$class', { value: $class });
    Object.assign(proto, methods);

    const all = cases.map(([name, ...value]) => Object.freeze(Object.assign(Object.create(proto), value.length ? { name, value: value[0] } : { name })));
    const $cases = Object.create(null);
    for (const c of all) $cases[c.name] = c;

    const definition = { $cases, ...statics, cases: () => all.slice() };

    if (cases.some((c) => c.length > 1)) {
        const intBacked = typeof all[0]?.value === 'number';
        /** The value as the enum's backing type takes it (PHP coerces a numeric string to int, an int to string). */
        const backing = (v) => {
            if (intBacked && typeof v === 'string' && /^-?\d+$/.test(v.trim())) return Number(v.trim());
            if (!intBacked && typeof v === 'number') return toStr(v);
            if (intBacked ? typeof v !== 'number' : typeof v !== 'string') {
                throw typeError(`${$class}::from(): Argument #1 ($value) must be of type ${intBacked ? 'int' : 'string'}, ${typeName(v)} given`);
            }
            return v;
        };
        definition.tryFrom = (v) => {
            const value = backing(v);
            return all.find((c) => c.value === value) ?? null;
        };
        definition.from = (v) => {
            const found = definition.tryFrom(v);
            if (found === null) throw new PhpError('ValueError', `${intBacked ? toStr(v) : JSON.stringify(toStr(v))} is not a valid backing value for enum ${$class}`);
            return found;
        };
    }

    return definition;
}

/** $case->label(): a method of the enum a case belongs to. */
export function enumCall(v, method, args) {
    if (!(v instanceof EnumCase)) throw new PhpError('Error', `Call to a member function ${method}() on ${typeName(v)}`);
    if (typeof v[method] !== 'function') throw new PhpError('Error', `Call to undefined method ${v.$class}::${method}()`);
    return v[method](...args);
}
