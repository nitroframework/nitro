/**
 * What the runtime and the server must agree on, from js/protocol.json, which the server reads
 * too (Support/Protocol.php): the one definition both sides take it from.
 */
import protocol from '../../protocol.json' with { type: 'json' };

/** What every Fusion attribute's name starts with: Protocol::namespace(). */
export const NAMESPACE = protocol.namespace;

/** Among a child's props: the value of its tag's fusion:model: Protocol::modelProp(). */
export const MODEL_PROP = protocol.compiled.model;

/** The engine of a view Fusion compiles itself: Protocol::bladeEngine(). Its definitions name no engine. */
export const BLADE_ENGINE = protocol.view.bladeEngine;

/** Attributes Fusion puts on elements, by role (id, name, snapshot, page, placeholder, serverHtml, hydrate, static, handler, directive): Protocol::attribute(). */
export const ATTRIBUTES = protocol.attributes;

/** A CSS selector for an attribute by its name: [fusion\:id]. */
export const selectorOf = (name) => `[${name.replace(/:/g, '\\:')}]`;

/** A CSS selector for an attribute by its role: selector('id') is [fusion\:id]. */
export const selector = (role) => selectorOf(ATTRIBUTES[role]);

/** What changed besides properties, for what reads it to render again (errors, deferred, unsaved, status, uploads, scroll): Protocol::signal(). */
export const SIGNALS = protocol.signals;

/** HTTP headers, by role (navigate, version, partial, id, only, once, location, call): Protocol::header(). */
export const HEADERS = protocol.headers;

/** Paths under the endpoint, by role (update, upload, ...): Protocol::path(). The chunks' URL comes whole from @fusionScripts. */
export const PATHS = protocol.paths;

/** The element a JavaScript view renders into, on the server (ScriptEngine) and in the browser. */
export const VIEW_WRAPPER = protocol.view.wrapper;

export default protocol;
