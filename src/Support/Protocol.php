<?php

namespace Nitro\Support;

/**
 * What the server and the browser must agree on, read from js/protocol.json, which the runtime
 * imports too: the one definition both sides take it from.
 */
final class Protocol
{
    /** @var array<string, mixed>|null */
    private static ?array $protocol = null;

    /** What every Nitro attribute's name starts with: nitro:click, nitro:model, nitro:id. */
    public static function namespace(): string
    {
        return self::get('namespace');
    }

    /** The engine of a view Nitro compiles itself (BladeEngine): a component's, unless #[View] names a JavaScript framework's. */
    public static function bladeEngine(): string
    {
        return self::get('view')['bladeEngine'];
    }

    /** Among a child's props: the value of its tag's nitro:model, for its #[Modelable] property. */
    public static function modelProp(): string
    {
        return self::get('compiled')['model'];
    }

    /**
     * An attribute Nitro puts on an element for the runtime, by its role:
     *
     *   id, name, snapshot   a component's root: its id, its name, its signed state
     *   page                 a routed page's root: the layout it renders in
     *   placeholder          where the runtime puts a child it creates
     *   serverHtml           a JavaScript view's element holds the server's HTML: hydrate it
     *   hydrate, static      an island: when it starts; never (also written on its tag)
     *   handler              the prefix of a handler's mark (nitro:h-click, nitro:h-poll)
     *   directive            an element with directives of the app's own (Nitro.directive())
     */
    public static function attribute(string $role): string
    {
        return self::get('attributes')[$role] ?? throw new \LogicException("js/protocol.json has no attribute [{$role}]");
    }

    /**
     * What changed besides properties, for what reads it to render again: errors (the validation
     * messages), deferred (deferred properties arrived), unsaved, status (a call's), uploads, scroll
     * (a page's scroll properties changed).
     */
    public static function signal(string $role): string
    {
        return self::get('signals')[$role] ?? throw new \LogicException("js/protocol.json has no signal [{$role}]");
    }

    /**
     * An HTTP header between the runtime and the server, by its role:
     *
     *   navigate   a visit or a partial reload asks for the page's data (JSON), not its HTML
     *   version    the bundle the page runs: another build's visit is answered 409
     *   partial    a partial reload: the id of the page asking (deferred properties, reload())
     *   id         a markup-first visit: the id the browser gave the page it rendered early
     *   only       a partial reload's properties, comma-separated (all when absent)
     *   once       the once data the browser has (Nitro::once()), comma-separated
     *   location   where a 409 answer sends the browser for a full load
     *   call       marks a #[Server] call or an upload the runtime made (for the app's middleware and logs)
     */
    public static function header(string $role): string
    {
        return self::get('headers')[$role] ?? throw new \LogicException("js/protocol.json has no header [{$role}]");
    }

    /**
     * A path under Nitro's endpoint (nitro.endpoint), by role: update (#[Server] calls),
     * upload, preview (an upload's), runtime (nitro.js), bundle (app.js), chunk (app/{id}.js:
     * {id} is the chunk's).
     */
    public static function path(string $role): string
    {
        return self::get('paths')[$role] ?? throw new \LogicException("js/protocol.json has no path [{$role}]");
    }

    /** What a Blade component's view's input telling whether a slot renders empty is named by: the prefix to the slot's name ($__empty_default). */
    public static function emptySlot(): string
    {
        return self::get('compiled')['emptySlot'];
    }

    /** A section of the protocol ('urlValues', ...). */
    public static function get(string $section): mixed
    {
        self::$protocol ??= json_decode((string) file_get_contents(dirname(__DIR__, 2).'/js/protocol.json'), true, flags: JSON_THROW_ON_ERROR);

        return self::$protocol[$section] ?? throw new \LogicException("js/protocol.json has no [{$section}]");
    }
}
