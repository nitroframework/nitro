/**
 * Addresses: where the browser may be sent, a page's address apart from the part of it a #hash
 * names, and #[Url] values written into a query string as PHP reads them back.
 */
import * as P from '../../php.js';

/**
 * A URL the browser may be sent to: http(s) only. A redirect can come from what a visitor typed
 * (a "return to" URL); javascript: or data: there would run script on the page.
 */
export function webUrl(href, base) {
    let url;
    try {
        url = new URL(href, base);
    } catch {
        return null;
    }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        console.error(`Nitro: not sending the browser to ${url.protocol} URLs`);
        return null;
    }
    return url;
}

/**
 * A page's address without its #hash: two URLs that differ only there are the same page, one
 * part of it or another, which the browser scrolls to without asking the server.
 */
export function pageOf(href) {
    const url = new URL(href);
    url.hash = '';
    return url.href;
}

/**
 * Whether a #[Url] value stays out of the URL: null; or equal to except when it has one, else to
 * the declared default (unless keep). Compared as encoded, so types count (1 is not "1").
 */
export function omitted(value, prop) {
    if (value === null) return true;
    const encoded = JSON.stringify(P.encode(value));
    if ('except' in prop.url) return encoded === JSON.stringify(prop.url.except);
    return !prop.url.keep && 'default' in prop && encoded === JSON.stringify(prop.default);
}

/** A value as PHP reads it back from a query string: key=v, key[]=v for lists, key[k]=v for maps. */
export function appendQuery(params, key, value) {
    if (P.isArray(value)) {
        const list = Array.isArray(value);
        for (const [k, v] of P.entries(value)) appendQuery(params, list ? `${key}[]` : `${key}[${k}]`, v);
        return;
    }
    params.append(key, value === true ? '1' : value === false ? '0' : P.toStr(value));
}
