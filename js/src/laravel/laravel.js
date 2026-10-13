/**
 * Laravel's helpers in the browser, as Laravel implements them: route() (RouteUrlGenerator),
 * url() and asset() (UrlGenerator), __() / trans() (Translator's replacements), config().
 * Compiled code calls them as B.route(...), ... (blade.js exports them).
 *
 * What they need comes with the page (@nitroScripts, Nitro::globals()), and only for what the
 * compiled code names: those route definitions, those translations, that public config.
 */
import { decode, entries, isArray, key as phpKey, toStr } from '../php.js';

export const globals = { url: '', asset: '', locale: 'en', routes: {}, lang: {}, config: {}, shared: [], once: {}, flash: [] };

/** RouteUrlGenerator::$dontEncode */
const DONT_ENCODE = {
    '%2F': '/', '%40': '@', '%3A': ':', '%3B': ';', '%2C': ',', '%3D': '=', '%2B': '+',
    '%21': '!', '%2A': '*', '%7C': '|', '%3F': '?', '%26': '&', '%23': '#', '%25': '%',
};

/** PHP's rawurlencode (RFC 3986): encodeURIComponent leaves !'()* alone, PHP does not. */
function rawurlencode(value) {
    return encodeURIComponent(toStr(value)).replace(/[!'()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase());
}

/** UrlGenerator::isValidUrl() */
function isValidUrl(path) {
    return /^(#|\/\/|https?:\/\/|(mailto|tel|sms):)/i.test(path);
}

function trimSlashes(s) {
    return s.replace(/^\/+|\/+$/g, '');
}

/** UrlGenerator::format() */
function format(root, path) {
    return trimSlashes(root + '/' + trimSlashes(path));
}

/** url($path, $extra): the application's URL of a path (extra values as more segments). */
export function url(path, extra = []) {
    path = toStr(path);
    if (isValidUrl(path)) return path;
    const tail = entries(isArray(extra) ? extra : [extra]).map(([, v]) => rawurlencode(v)).join('/');
    const at = path.indexOf('?');
    const [bare, query] = at === -1 ? [path, ''] : [path.slice(0, at), path.slice(at)];
    return format(globals.url, '/' + trimSlashes(bare + '/' + tail)) + query;
}

/** asset($path) */
export function asset(path) {
    path = toStr(path);
    if (isValidUrl(path)) return path;
    return globals.asset + '/' + trimSlashes(path);
}

/** Arr::query(): http_build_query, RFC 3986, nested keys as a[b]. */
function httpBuildQuery(list, prefix = null) {
    const parts = [];
    for (const [k, v] of list) {
        if (v === null || v === undefined) continue;
        const key = prefix === null ? toStr(k) : `${prefix}[${toStr(k)}]`;
        if (isArray(v)) {
            const nested = httpBuildQuery(entries(v), key);
            if (nested !== '') parts.push(nested);
        } else {
            parts.push(rawurlencode(key) + '=' + rawurlencode(v === true ? 1 : v === false ? 0 : v));
        }
    }
    return parts.join('&');
}

/**
 * A value in a route. A model is an array in the browser: its route key, the field the route
 * binds by ({post:slug}) or its "id" (a model's default route key).
 */
function routeValue(value, field) {
    if (!isArray(value)) return value;
    const found = entries(value).find(([k]) => k === (field ?? 'id'));
    return found ? found[1] : value;
}

/** RouteUrlGenerator::encodeParameter() */
function encodeParameter(value) {
    return value.replace(/%/g, '%25').replace(/\?/g, '%3F').replace(/#/g, '%23');
}

/**
 * route($name, $parameters, $absolute), as RouteUrlGenerator builds it: named values fill the
 * parameters of their name, positional ones the required parameters (then the rest, in order),
 * whatever is left becomes the query string.
 */
export function route(name, parameters = [], absolute = true) {
    name = toStr(name);
    const definition = globals.routes[name];
    if (!definition) throw new Error(`Route [${name}] not defined.`);
    if (definition.domain) throw new Error(`Route [${name}] has a domain: the browser does not generate those; build the URL on the server.`);

    const uri = definition.uri;
    const routeParameters = [...uri.matchAll(/\{(\w+)(?::(\w+))?(\?)?\}/g)].map((m) => ({ name: m[1], field: m[2] ?? null, optional: m[3] === '?' }));
    let given = entries(isArray(parameters) ? parameters : [parameters]);

    /** formatParameters(): the values named for route parameters, then positional ones. */
    const named = new Map();
    const required = [];
    for (const parameter of routeParameters) {
        const at = given.findIndex(([k, v]) => k === parameter.name && v !== null && v !== undefined);
        if (at !== -1) {
            named.set(parameter.name, given[at][1]);
            given.splice(at, 1);
            continue;
        }
        if (!parameter.optional) required.push(parameter.name);
        named.set(parameter.name, '');
    }
    const namedQuery = given.filter(([k]) => typeof k === 'string');
    let positional = given.filter(([k]) => typeof k !== 'string').map(([, v]) => v);

    if (positional.length === required.length) {
        for (const parameter of [...required].reverse()) {
            if (positional.length === 0) break;
            named.set(parameter, positional.pop());
        }
    }

    const keys = [...named.keys()];
    const empty = [...named.values()].filter((v) => v === '').length;
    let offset = 0;
    if (required.length && positional.length !== empty) {
        offset = keys.indexOf(required[0]);
        const remaining = empty - offset - positional.length;
        if (remaining < 0) offset += remaining;
        if (offset < 0) offset = 0;
    } else if (!required.length && positional.length !== 0) {
        let left = positional.length;
        for (let i = keys.length - 1; i >= 0; i--) {
            if (named.get(keys[i]) === '') {
                offset = i;
                if (--left === 0) break;
            }
        }
    }
    for (let i = offset; i < keys.length; i++) {
        if (named.get(keys[i]) !== '') continue;
        if (positional.length) named.set(keys[i], positional.shift());
    }

    /** The field a parameter binds by: Laravel keeps {post:slug}'s "slug" apart from the URI. */
    const fields = Object.fromEntries(routeParameters.map((p) => [p.name, definition.bindings?.[p.name] ?? p.field]));
    const values = new Map([...named].map(([k, v]) => [k, routeValue(v, fields[k])]));
    for (const [k, v] of namedQuery) values.set(k, v);
    positional.forEach((v, i) => values.set(i, v));

    /** replaceRouteParameters(): each {parameter} its value; optional ones without a value go. */
    let path = uri.replace(/\{(\w+)(?::\w+)?\??\}/g, (whole, key) => {
        const v = values.get(key);
        values.delete(key);
        return v !== undefined && v !== null && v !== '' ? encodeParameter(toStr(v)) : whole;
    });
    path = trimSlashes(path.replace(/\{\w+(?::\w+)?\?\}/g, ''));

    const missing = [...path.matchAll(/\{(\w+)(?::\w+)?\}/g)].map((m) => m[1]);
    if (missing.length) {
        throw new Error(`Missing required parameter${missing.length > 1 ? 's' : ''} for [Route: ${name}] [URI: ${uri}] [Missing parameter${missing.length > 1 ? 's' : ''}: ${missing.join(', ')}].`);
    }

    /** getRouteQueryString(): string keys through Arr::query, then numeric ones as they are. */
    let query = httpBuildQuery([...values].filter(([k]) => typeof k === 'string'));
    const numeric = [...values].filter(([k]) => typeof k !== 'string');
    if (numeric.length) query += '&' + numeric.map(([, v]) => toStr(v)).join('&');
    query = query.replace(/^&+|&+$/g, '');

    let full = format(globals.url, path) + (query === '' ? '' : '?' + query);
    full = rawurlencode(full).replace(/%[0-9A-F]{2}/g, (c) => DONT_ENCODE[c] ?? c);

    return absolute ? full : '/' + full.replace(/^(\/\/|[^/?])+/, '').replace(/^\/+/, '');
}

/**
 * __($key, $replace) / trans(): the line in the page's locale (the key itself when there is
 * none), :placeholders replaced as Laravel does (:name, :Name, :NAME; longer keys first).
 */
export function trans(key, replace = []) {
    return replaced(line(key), entries(isArray(replace) ? replace : []).map(([k, v]) => [toStr(k), toStr(v)]));
}

/** The line of a key in the page's locale, the key itself when there is none. */
function line(key) {
    key = toStr(key);
    return Object.prototype.hasOwnProperty.call(globals.lang, key) ? globals.lang[key] : key;
}

/**
 * trans_choice($key, $number, $replace) / Blade's @choice: Translator::choice(), the line's
 * segment for the number (MessageSelector: {0} / [2,*] conditions first, then the locale's plural
 * rule), :count the number unless given. A countable number is its count.
 */
export function choice(key, number, replace = []) {
    if (isArray(number)) number = entries(number).length;
    const pairs = entries(isArray(replace) ? replace : []).map(([k, v]) => [toStr(k), toStr(v)]);
    if (!pairs.some(([k]) => k === 'count')) pairs.push(['count', toStr(number)]);
    return replaced(choose(line(key), Number(number), globals.locale), pairs);
}

/** MessageSelector::choose(). */
function choose(text, number, locale) {
    const segments = text.split('|');
    for (const part of segments) {
        const match = /^[{[]([-?\d|*,.*]*)[}\]](.*)/s.exec(part);
        if (!match) continue;
        const [, condition, value] = match;
        if (condition.includes(',')) {
            const [from, to] = [condition.slice(0, condition.indexOf(',')), condition.slice(condition.indexOf(',') + 1)];
            if ((to === '*' && number >= Number(from)) || (from === '*' && number <= Number(to)) || (number >= Number(from) && number <= Number(to))) return value.trim();
        }
        if (Number(condition) === number && condition !== '') return value.trim();
    }
    const stripped = segments.map((part) => part.replace(/^[{[][-?\d|*,.*]*[}\]]/, ''));
    const index = pluralIndex(locale, Math.abs(number));
    return stripped.length === 1 || stripped[index] === undefined ? stripped[0] : stripped[index];
}

/** The locales of each of MessageSelector::getPluralIndex()'s rules (any other: 0). */
const PLURAL_GROUPS = [
    [(n) => (n == 1 ? 0 : 1), 'af af_ZA bn bn_BD bn_IN bg bg_BG ca ca_AD ca_ES ca_FR ca_IT da da_DK de de_AT de_BE de_CH de_DE de_LI de_LU el el_CY el_GR en en_AG en_AU en_BW en_CA en_DK en_GB en_HK en_IE en_IN en_NG en_NZ en_PH en_SG en_US en_ZA en_ZM en_ZW eo eo_US es es_AR es_BO es_CL es_CO es_CR es_CU es_DO es_EC es_ES es_GT es_HN es_MX es_NI es_PA es_PE es_PR es_PY es_SV es_US es_UY es_VE et et_EE eu eu_ES eu_FR fa fa_IR fi fi_FI fo fo_FO fur fur_IT fy fy_DE fy_NL gl gl_ES gu gu_IN ha ha_NG he he_IL hu hu_HU is is_IS it it_CH it_IT ku ku_TR lb lb_LU ml ml_IN mn mn_MN mr mr_IN nah nb nb_NO ne ne_NP nl nl_AW nl_BE nl_NL nn nn_NO no om om_ET om_KE or or_IN pa pa_IN pa_PK pap pap_AN pap_AW pap_CW ps ps_AF pt pt_BR pt_PT so so_DJ so_ET so_KE so_SO sq sq_AL sq_MK sv sv_FI sv_SE sw sw_KE sw_TZ ta ta_IN ta_LK te te_IN tk tk_TM ur ur_IN ur_PK zu zu_ZA'],
    [(n) => (n == 0 || n == 1 ? 0 : 1), 'am am_ET bh fil fil_PH fr fr_BE fr_CA fr_CH fr_FR fr_LU gun hi hi_IN hy hy_AM ln ln_CD mg mg_MG nso nso_ZA ti ti_ER ti_ET wa wa_BE xbr'],
    [(n, i) => (i % 10 == 1 && i % 100 != 11 ? 0 : i % 10 >= 2 && i % 10 <= 4 && (i % 100 < 10 || i % 100 >= 20) ? 1 : 2), 'be be_BY bs bs_BA hr hr_HR ru ru_RU ru_UA sr sr_ME sr_RS uk uk_UA'],
    [(n) => (n == 1 ? 0 : n >= 2 && n <= 4 ? 1 : 2), 'cs cs_CZ sk sk_SK'],
    [(n) => (n == 1 ? 0 : n == 2 ? 1 : 2), 'ga ga_IE'],
    [(n, i) => (i % 10 == 1 && i % 100 != 11 ? 0 : i % 10 >= 2 && (i % 100 < 10 || i % 100 >= 20) ? 1 : 2), 'lt lt_LT'],
    [(n, i) => (i % 100 == 1 ? 0 : i % 100 == 2 ? 1 : i % 100 == 3 || i % 100 == 4 ? 2 : 3), 'sl sl_SI'],
    [(n, i) => (i % 10 == 1 ? 0 : 1), 'mk mk_MK'],
    [(n, i) => (n == 1 ? 0 : n == 0 || (i % 100 > 1 && i % 100 < 11) ? 1 : i % 100 > 10 && i % 100 < 20 ? 2 : 3), 'mt mt_MT'],
    [(n, i) => (n == 0 ? 0 : i % 10 == 1 && i % 100 != 11 ? 1 : 2), 'lv lv_LV'],
    [(n, i) => (n == 1 ? 0 : i % 10 >= 2 && i % 10 <= 4 && (i % 100 < 12 || i % 100 > 14) ? 1 : 2), 'pl pl_PL'],
    [(n) => (n == 1 ? 0 : n == 2 ? 1 : n == 8 || n == 11 ? 2 : 3), 'cy cy_GB'],
    [(n, i) => (n == 1 ? 0 : n == 0 || (i % 100 > 0 && i % 100 < 20) ? 1 : 2), 'ro ro_RO'],
    [(n, i) => (n == 0 ? 0 : n == 1 ? 1 : n == 2 ? 2 : i % 100 >= 3 && i % 100 <= 10 ? 3 : i % 100 >= 11 && i % 100 <= 99 ? 4 : 5), 'ar ar_AE ar_BH ar_DZ ar_EG ar_IN ar_IQ ar_JO ar_KW ar_LB ar_LY ar_MA ar_OM ar_QA ar_SA ar_SD ar_SS ar_SY ar_TN ar_YE'],
];
const PLURAL_RULES = new Map(PLURAL_GROUPS.flatMap(([rule, locales]) => locales.split(' ').map((locale) => [locale, rule])));

/** MessageSelector::getPluralIndex(): n the number, i its integer part (PHP's (int) $number % ...). */
function pluralIndex(locale, number) {
    const rule = PLURAL_RULES.get(locale);
    return rule ? rule(number, Math.trunc(number)) : 0;
}

/** Translator::makeReplacements(): :key, :Key and :KEY, the longest first (strtr). */
function replaced(line, pairs) {
    if (pairs.length === 0) return line;

    const map = new Map();
    for (const [k, v] of pairs) {
        map.set(':' + k.charAt(0).toUpperCase() + k.slice(1), v.charAt(0).toUpperCase() + v.slice(1));
        map.set(':' + k.toUpperCase(), v.toUpperCase());
        map.set(':' + k, v);
    }
    const tokens = [...map.keys()].sort((a, b) => b.length - a.length).map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    return line.replace(new RegExp(tokens.join('|'), 'g'), (m) => map.get(m));
}

/**
 * $this->shared($key, $default): Nitro::share()'s data, as the last page, nitro:navigate visit
 * or #[Server] call brought it, read as data_get() reads it (dot notation, the default when a
 * key is missing).
 */
export function shared(key = null, fallback = null) {
    return dataGet(decode(globals.shared), key, fallback);
}

/** $this->flashed($key, $default): Nitro::flash()'s data, as the last page, visit or #[Server] call brought it. */
export function flashed(key = null, fallback = null) {
    return dataGet(decode(globals.flash), key, fallback);
}

/** data_get(): a value by key in dot notation, the default when a key is missing. */
function dataGet(value, key, fallback) {
    if (key === null || key === undefined) return value;

    for (const segment of toStr(key).split('.')) {
        const k = phpKey(segment);
        if (value instanceof Map && value.has(k)) {
            value = value.get(k);
        } else if (Array.isArray(value) && typeof k === 'number' && k >= 0 && k < value.length) {
            value = value[k];
        } else {
            return fallback;
        }
    }

    return value;
}

/** config($key, $default): a key the application made public (nitro.public_config). */
export function config(key, fallback = null) {
    key = toStr(key);
    return Object.prototype.hasOwnProperty.call(globals.config, key) ? decode(globals.config[key]) : fallback;
}
