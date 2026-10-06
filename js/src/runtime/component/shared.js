/**
 * What responses bring besides components' state: Fusion::share()'s data (with Fusion::once()'s,
 * kept between responses) and Fusion::flash()'s; when either changes, what reads it renders again.
 * A part of the runtime.
 */
import { globals } from '../../laravel/laravel.js';
import * as P from '../../php.js';

export class SharedData {
    constructor(runtime) {
        this.runtime = runtime;
        /** Fusion::once() shared data the browser keeps between responses: key => {value, expires (ms or null)}. */
        this.once = new Map();
    }

    /**
     * What a response brings besides state: Fusion::share()'s data (with the once data kept
     * from earlier responses) and Fusion::flash()'s. Flash data belongs to the page it came with:
     * a visit replaces it; a reload or a #[Server] call only when it brings some.
     */
    receive(answer, except = null, visit = false) {
        /** A page's data (a visit, a partial reload): the stores its components use, for those not made yet. */
        if (answer.fusion === 1 && answer.stores) this.runtime.takeStores(answer.stores);
        if (answer.shared !== undefined) this.setShared(this.withOnce(answer.shared, answer.once ?? {}), except);
        if (answer.flash === undefined || answer.flash === null) return;
        const flash = P.decode(answer.flash);
        if (visit || (flash instanceof Map && flash.size > 0)) this.setFlash(answer.flash, except);
    }

    /**
     * Fusion::once(): the once data a response sent is kept; what it left out (the browser said it
     * has it) is put back. Keys the response does not name as once are let go.
     */
    withOnce(shared, once) {
        const decoded = P.decode(shared);
        const data = decoded instanceof Map ? new Map(decoded) : new Map();
        for (const [key, expires] of Object.entries(once)) {
            if (data.has(key)) {
                this.once.set(key, { value: data.get(key), expires: expires === null ? null : expires * 1000 });
            } else if (this.once.has(key)) {
                data.set(key, this.once.get(key).value);
            }
        }
        for (const key of [...this.once.keys()]) {
            if (!(key in once)) this.once.delete(key);
        }
        return data.size === 0 && !(decoded instanceof Map) ? shared : P.encode(data);
    }

    /** The once data the browser has and that has not expired: sent as X-Fusion-Once. */
    onceHeader() {
        const now = Date.now();
        return [...this.once].filter(([, kept]) => kept.expires === null || kept.expires > now).map(([key]) => key).join(',');
    }

    /**
     * Fusion::share()'s data from the server (a #[Server] call's answer, a visited page). When it
     * changed, what reads it ("$shared") renders again; `except` renders with its own update.
     */
    setShared(shared, except = null) {
        if (JSON.stringify(shared) === JSON.stringify(globals.shared)) return;
        globals.shared = shared;
        for (const component of this.runtime.components()) {
            component.dirty.add('$shared');
            if (component !== except) component.update();
        }
    }

    /** Fusion::flash()'s data from a response: what reads it ("$flash") renders again, and fusion:flash announces it. */
    setFlash(flash, except = null) {
        if (JSON.stringify(flash) !== JSON.stringify(globals.flash)) {
            globals.flash = flash;
            for (const component of this.runtime.components()) {
                component.dirty.add('$flash');
                if (component !== except) component.update();
            }
        }
        this.announceFlash(flash);
    }

    announceFlash(flash) {
        const data = P.decode(flash ?? []);
        if (data instanceof Map && data.size > 0) this.runtime.emit('fusion:flash', { flash: plain(data) });
    }
}

/** A PHP array as plain JS (a Map as an object), for an event's detail. */
function plain(value) {
    if (value instanceof Map) return Object.fromEntries([...value].map(([k, v]) => [k, plain(v)]));
    if (Array.isArray(value)) return value.map(plain);
    return value;
}
