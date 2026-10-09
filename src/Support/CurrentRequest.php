<?php

namespace Nitro\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * The request being answered, and its session: null when there is none (a console command, a
 * queued job, a route without the session middleware). Asked for each time, never kept: Nitro
 * serves many requests with one application.
 *
 * @internal
 */
final class CurrentRequest
{
    public static function of(Container $app): ?Request
    {
        $request = $app->bound('request') ? $app->make('request') : null;

        return $request instanceof Request ? $request : null;
    }

    public static function session(Container $app): ?Session
    {
        $request = self::of($app);

        return $request !== null && $request->hasSession() ? $request->session() : null;
    }
}
