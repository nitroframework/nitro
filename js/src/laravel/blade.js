/**
 * Blade's helpers, as Laravel implements them, for compiled templates:
 * $loop (View\Concerns\ManagesLoops), @class / @style (Arr::toCssClasses / toCssStyles),
 * @csrf (csrf_field) and @method (method_field).
 */
import { bool, count, entries, isArray, toStr } from '../php.js';

/** Blade's loop state for one @foreach: what addLoop() sets up. */
export function loop(data, parent) {
    const length = isArray(data) ? count(data) : null;
    return {
        iteration: 0,
        index: 0,
        remaining: length,
        count: length,
        first: true,
        last: length === null ? null : length === 1,
        odd: false,
        even: true,
        depth: parent ? parent.depth + 1 : 1,
        parent: parent ?? null,
    };
}

/** incrementLoopIndices(): the $loop of the next iteration. */
export function next(state) {
    const it = state.iteration;
    Object.assign(state, {
        iteration: it + 1,
        index: it,
        first: it === 0,
        odd: !state.odd,
        even: !state.even,
        remaining: state.count === null ? null : state.remaining - 1,
        last: state.count === null ? null : it === state.count - 1,
    });
    return Object.freeze({ ...state });
}

function wrap(value) {
    if (value === null || value === undefined) return [];
    return isArray(value) ? entries(value) : [[0, value]];
}

/** @class([...]) */
export function classes(list) {
    const out = [];
    for (const [k, v] of wrap(list)) {
        if (typeof k === 'number') out.push(toStr(v));
        else if (bool(v)) out.push(toStr(k));
    }
    return out.join(' ');
}

/** @style([...]) */
export function styles(list) {
    const finish = (s) => (s.endsWith(';') ? s : s + ';');
    const out = [];
    for (const [k, v] of wrap(list)) {
        if (typeof k === 'number') out.push(finish(toStr(v)));
        else if (bool(v)) out.push(finish(toStr(k)));
    }
    return out.join(' ');
}

/** The CSRF token of the page, set by the runtime from @fusionScripts (and a visit's page): the one copy, for @csrf and the runtime's requests. */
export const session = { token: '' };

/** @csrf */
export const csrf = () => `<input type="hidden" name="_token" value="${session.token}" autocomplete="off">`;

/** @method('PUT') */
export const methodField = (method) => `<input type="hidden" name="_method" value="${toStr(method)}">`;

/** Laravel's helpers (route, url, asset, trans, config), $this->shared() and $this->flashed(), for compiled code as B.route(...) and so on. */
export { asset, config, flashed, globals, route, shared, trans, url } from './laravel.js';
