<?php

namespace Nitro\Fusion\Support;


/**
 * The hooks Fusion calls on a component, when it has them: the one list of their names, where
 * each runs, and which may be #[Server]. The compiler, the component's metadata and the browser
 * runtime all take them from here.
 *
 * On the server, called by Fusion itself (never compiled, never callable from the browser):
 *
 *   boot()                 every request: on creation and on each #[Server] call, before anything else
 *   mount(...)             once, on creation: the tag's or the route's values, and the container's
 *   hydrate()              each #[Server] call, once the state is restored, before the browser's changes
 *   dehydrate()            every request, last, before the state goes to the browser
 *   exception($e)          a #[Server] method threw: handled, unless the hook throws it again
 *   fallback($e)           it failed (to start, to render, a call) and fallbacks are on: what shows
 *                          in its place (a view, HTML, or text); Rendering\Fallbacks asks it
 *
 * In the browser, called by the runtime (compiled; #[Server] where noted, a request when it runs):
 *
 *   mounted()              once the component is live in the page (may be #[Server])
 *   rendered()             after each render that changed the view (never #[Server]: a request per render)
 *   destroyed()            when it leaves the page (may be #[Server])
 *
 * The property hooks (updating*, updated*) are PropertyHooks'.
 */
final class ComponentHooks
{
    public const BOOT = 'boot';

    public const MOUNT = 'mount';

    public const HYDRATE = 'hydrate';

    public const DEHYDRATE = 'dehydrate';

    public const EXCEPTION = 'exception';

    public const FALLBACK = 'fallback';

    public const MOUNTED = 'mounted';

    public const RENDERED = 'rendered';

    public const DESTROYED = 'destroyed';

    /** Called on the server by Fusion. */
    public const SERVER = [self::BOOT, self::MOUNT, self::HYDRATE, self::DEHYDRATE, self::EXCEPTION, self::FALLBACK];

    /** Called in the browser by the runtime. */
    public const BROWSER = [self::MOUNTED, self::RENDERED, self::DESTROYED];

    /** Browser hooks that may be #[Server]. */
    private const MAY_RUN_ON_SERVER = [self::MOUNTED, self::DESTROYED];

    /** Not a hook, but kept from the browser as they are: render(), which Fusion never calls (a component's view is its own). */
    private const RESERVED = ['render'];

    public static function isServerHook(string $method): bool
    {
        return in_array($method, self::SERVER, true);
    }

    public static function isBrowserHook(string $method): bool
    {
        return in_array($method, self::BROWSER, true);
    }

    /** A name Fusion keeps for itself: never compiled for the browser, never callable from it, never #[Server] or #[Computed]. */
    public static function isReserved(string $method): bool
    {
        return self::isServerHook($method) || in_array($method, self::RESERVED, true);
    }

    /** Why the browser hook cannot be #[Server]; null when it may be. */
    public static function whyNotServer(string $method): ?string
    {
        if (! self::isBrowserHook($method) || in_array($method, self::MAY_RUN_ON_SERVER, true)) {
            return null;
        }

        return "{$method}() runs after every render, and #[Server] would make each render a request: keep it in the browser, and have it call a #[Server] method when it must";
    }
}
