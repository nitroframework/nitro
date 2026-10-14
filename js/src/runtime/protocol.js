/**
 * What the runtime and the server must agree on, from js/protocol.json, which the server reads
 * too (Support/Protocol.php): the one definition both sides take it from.
 */
import protocol from '../../protocol.json' with { type: 'json' };

/** What every Nitro attribute's name starts with: Protocol::namespace(). */
export const NAMESPACE = protocol.namespace;

/** Among a child's props: the value of its tag's nitro:model: Protocol::modelProp(). */
export const MODEL_PROP = protocol.compiled.model;

/** A Blade component's view's input telling whether a slot renders empty: this, then the slot's name: Protocol::emptySlot(). */
export const EMPTY_SLOT = protocol.compiled.emptySlot;

/** The engine of a view Nitro compiles itself: Protocol::bladeEngine(). Its definitions name no engine. */
export const BLADE_ENGINE = protocol.view.bladeEngine;

/** Attributes Nitro puts on elements, by role (id, name, snapshot, page, placeholder, serverHtml, hydrate, static, handler, directive): Protocol::attribute(). */
export const ATTRIBUTES = protocol.attributes;

/** A CSS selector for an attribute by its name: [nitro\:id]. */
export const selectorOf = (name) => `[${name.replace(/:/g, '\\:')}]`;

/** A CSS selector for an attribute by its role: selector('id') is [nitro\:id]. */
export const selector = (role) => selectorOf(ATTRIBUTES[role]);

/** What changed besides properties, for what reads it to render again (errors, deferred, unsaved, status, uploads, scroll): Protocol::signal(). */
export const SIGNALS = protocol.signals;

/** HTTP headers, by role (navigate, version, partial, id, only, once, location, call): Protocol::header(). */
export const HEADERS = protocol.headers;

/** Paths under the endpoint, by role (update, upload, ...): Protocol::path(). The chunks' URL comes whole from @nitroScripts. */
export const PATHS = protocol.paths;

/** Server-Timing's metrics in development (Http\ServerTiming): the server's time on the request, its database's. */
export const TIMING = protocol.timing;

/** The element a JavaScript view renders into, on the server (ScriptEngine) and in the browser. */
export const VIEW_WRAPPER = protocol.view.wrapper;

/** The field beside a signed state that holds its #[Html] values, and, in a call, their fingerprints. */
export const HTML_FIELD = protocol.snapshot.html;

export default protocol;
