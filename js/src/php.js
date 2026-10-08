/**
 * PHP semantics for code compiled from PHP: values, operators and the functions a component may
 * call. Everything the compiler emits goes through here, so PHP's rules hold in the browser.
 *
 * Values:
 *   null, bool, number, string    as in JS (PHP int and float are both a JS number)
 *   a PHP array that is a list    a JS Array
 *   any other PHP array           a JS Map (insertion order, int or string keys, as in PHP)
 *
 * The invariant "Array if and only if array_is_list()" is kept by every function here, so a value
 * encodes to the same JSON in PHP and in JS. Arrays are values in PHP; nothing here mutates an
 * array in place: every write returns a new array (copying only the path it changes).
 */

export { ModelData, PhpError, entries, isArray, isList, isMap, key, normalize } from './php/core.js';
export { APPEND, arrayLiteral, count, empty, get, getForUpdate, getIn, isset, iter, listItem, ownKey, peek, prop, push, set, setIn, unhandledMatch, unsetIn } from './php/arrays.js';
export { bool, floatToString, isNumericString, param, spread, toArr, toFloat, toInt, toNum, toStr, typeName } from './php/conversions.js';
export { add, cmp, coalesce, concat, dec, div, e, eq, fromUtf8, gt, gte, identical, inc, lt, lte, mod, mul, neg, neq, notIdentical, pow, sub, utf8 } from './php/operators.js';
export { jsonDecode, jsonEncode } from './php/json.js';
export { number_format, round, sprintf } from './php/numbers.js';
export { callable, fns } from './php/functions.js';
export { Upload, decode, encode, upload } from './php/wire.js';
export { EnumCase, defineEnum, enumCall } from './php/enums.js';
