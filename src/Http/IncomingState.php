<?php

namespace Nitro\Fusion\Http;

use Illuminate\Http\Request;
use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\State\Snapshot;
use Nitro\Fusion\State\Snapshots;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The state a request brings from the browser (a #[Server] call's component and stores, an
 * upload's component), made trusted: signed by this application for this session and not expired
 * (Snapshots), a component's whole state (not a partial reload's), the component it names (an
 * obfuscated build's alias read as its own name) known to Fusion, and the page it was rendered
 * for still admitting the request (its persistent middleware, again); given back by its own
 * names (Snapshots::ownNames()). The one place this is done.
 */
final class IncomingState
{
    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly Components $components,
        private readonly PersistentMiddleware $persistent,
    ) {
    }

    /**
     * The envelope, trusted; with $expected, it must be that component's (a store a component
     * uses), else any Fusion knows.
     *
     * @throws HttpException 419 when the signature does not hold (Snapshots), 403 for a partial
     *                       reload's state or another component's, 404 for a component Fusion
     *                       does not know; what the page's middleware answers
     */
    public function snapshot(mixed $envelope, Request $request, ?string $expected = null): Snapshot
    {
        $snapshot = $this->snapshots->open($envelope);

        if ($snapshot->isPartial()) {
            throw new HttpException(403, 'A partial reload is not a component\'s state.');
        }

        $snapshot = $this->snapshots->ownNames($snapshot);

        if ($expected !== null && $snapshot->name !== $expected) {
            throw new HttpException(403, 'This is not the state of the store the component uses.');
        }

        if (! $this->components->has($snapshot->name)) {
            throw new HttpException(404, 'Unknown component.');
        }

        $this->persistent->check($snapshot->memo['route'] ?? [], $request);

        return $snapshot;
    }

    /**
     * A partial reload's state the browser laid over the component's (sent with a call), trusted:
     * signed, a partial reload's, of that component, rendered for the same page (the middleware
     * checked is that page's: another route of it could bind other models).
     *
     * @throws HttpException 419 when the signature does not hold, 403 when it is not that component's or page's
     */
    public function partial(mixed $envelope, Snapshot $over): Snapshot
    {
        $partial = $this->snapshots->open($envelope);

        if (! $partial->isPartial()) {
            throw new HttpException(403, 'This partial reload belongs to another component.');
        }

        $partial = $this->snapshots->ownNames($partial);

        if ($partial->id !== $over->id || $partial->name !== $over->name) {
            throw new HttpException(403, 'This partial reload belongs to another component.');
        }

        if (self::page($partial->memo['route'] ?? null) !== self::page($over->memo['route'] ?? null)) {
            throw new HttpException(403, 'This partial reload belongs to another page.');
        }

        return $partial;
    }

    /** The page a state was rendered for: the route's method and path. */
    private static function page(mixed $route): ?string
    {
        if (! is_array($route) || ! is_string($route['uri'] ?? null)) {
            return null;
        }

        return ($route['method'] ?? '').' '.(parse_url($route['uri'], PHP_URL_PATH) ?: '/');
    }
}
