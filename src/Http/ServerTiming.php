<?php

namespace Nitro\Http;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Nitro\Rendering\Devtools;
use Nitro\Support\Protocol;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware, while the dev overlay is on: the request's time on the server and its database's,
 * as a Server-Timing header (js/protocol.json's timing metrics). The overlay shows them with each
 * request, and the browser's own tools do too. Nothing when the overlay is off.
 */
final class ServerTiming
{
    public function __construct(
        private readonly Container $app,
        private readonly Devtools $devtools,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->devtools->enabled()) {
            return $next($request);
        }

        $started = hrtime(true);

        /** A long-running server keeps its connections: their totals count from this request on. */
        foreach ($this->connections() as $connection) {
            $connection->resetTotalQueryDuration();
        }

        $response = $next($request);

        $db = array_sum(array_map(fn (Connection $connection) => $connection->totalQueryDuration(), $this->connections()));
        $timing = Protocol::get('timing');
        $response->headers->set(Protocol::header('timing'), sprintf(
            '%s;dur=%.1f, %s;dur=%.1f',
            $timing['app'],
            (hrtime(true) - $started) / 1e6,
            $timing['db'],
            $db,
        ));

        return $response;
    }

    /** @return list<Connection> */
    private function connections(): array
    {
        if (! $this->app->bound('db')) {
            return [];
        }

        return array_values(array_filter($this->app->make('db')->getConnections(), fn ($connection) => $connection instanceof Connection));
    }
}
