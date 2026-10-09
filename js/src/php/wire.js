/** The wire format between PHP and JS, and the uploads it carries. Part of php.js. */
import { ModelData, PhpError, entries, isArray, key, normalize } from './core.js';
import { typeName } from './conversions.js';
import { EnumCase } from './enums.js';

// ---------------------------------------------------------------- the wire format

/**
 * Values between PHP and JS: JSON, where every PHP array that is not a list is the object
 * {"$m": [[key, value], ...]}, so key order and integer keys survive. Mirrors
 * Nitro\Support\Wire.
 */
export function decode(x) {
    if (Array.isArray(x)) return x.map(decode);
    if (x !== null && typeof x === 'object') {
        if (Array.isArray(x.$m)) return normalize(new Map(x.$m.map(([k, v]) => [key(k), decode(v)])));
        if (x.$u !== undefined && Object.keys(x).length === 1) return new Upload(x.$u);
        if (x.$o !== undefined && Object.keys(x).length === 1) return new ModelData(entries(decode(x.$o)));
        return normalize(new Map(Object.keys(x).map((k) => [key(k), decode(x[k])])));
    }
    return x;
}

export function encode(v) {
    if (v === undefined) return null;
    if (v instanceof Upload) return { $u: v.wire };
    /** A backed enum travels as its value, as Wire::encode() sends one (the server makes the case of it). */
    if (v instanceof EnumCase) {
        if (!('value' in v)) throw new PhpError('Error', `${v.$class}::${v.name} is a case of a pure enum: it has no value to send to the server`);
        return v.value;
    }
    if (Array.isArray(v)) return v.map(encode);
    if (v instanceof ModelData) return { $o: { $m: [...v.entries()].map(([k, x]) => [k, encode(x)]) } };
    if (v instanceof Map) return { $m: [...v.entries()].map(([k, x]) => [k, encode(x)]) };
    if (typeof v === 'number' && !Number.isFinite(v)) throw new PhpError('Error', 'INF and NAN cannot be sent to the server');
    return v;
}

// ---------------------------------------------------------------- uploads

/**
 * A file a nitro:model file input uploaded (Nitro\Upload on the server): its signed
 * reference, sent back unchanged, and what a view may show of it. Upload::toWire() made it.
 */
export class Upload {
    constructor(wire) {
        this.wire = wire;
    }

    temporaryUrl() {
        if (this.wire.url === null || this.wire.url === undefined) {
            throw new PhpError('RuntimeException', `${this.wire.name} is not an image: only images have a temporary URL.`);
        }
        return this.wire.url;
    }

    getClientOriginalName() {
        return this.wire.name;
    }

    getClientOriginalExtension() {
        return this.wire.ext;
    }

    getSize() {
        return this.wire.size;
    }

    getMimeType() {
        return this.wire.mime;
    }
}

/** $value->temporaryUrl() and the other Upload methods (Globals::UPLOAD_METHODS), as PHP calls them. */
export function upload(v, method, args) {
    if (!(v instanceof Upload)) throw new PhpError('Error', `Call to a member function ${method}() on ${typeName(v)}`);
    return v[method](...args);
}
