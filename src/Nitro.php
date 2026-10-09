<?php

namespace Nitro;

use Closure;
use Illuminate\Contracts\Container\Container;
use Nitro\Browser\Browser;
use Nitro\Browser\BrowserOnlyException;
use Nitro\State\MethodCache;
use Nitro\State\SharedData;
use Nitro\State\Stores;

/**
 * Nitro for an application's code (the Nitro facade): shared and flash data, stores, the
 * browser's APIs, cached results. Each method hands the work to the part that does it; Nitro's own parts never
 * come through here, they take what they need in their constructors (NitroServiceProvider).
 */
final class Nitro
{
    public function __construct(private readonly Container $app)
    {
    }

    /**
     * The browser's APIs (clipboard, storage, canvas, sockets), in a component's browser methods:
     * compiled to JavaScript. On the server it throws.
     */
    public function browser(): Browser
    {
        throw BrowserOnlyException::make();
    }

    /**
     * This request's store of a class: the same one for every component that uses it, made with
     * its mount() (on the server) the first time one asks.
     */
    public function store(string $class): Store
    {
        return $this->part(Stores::class)->store($class);
    }

    /**
     * Data every component can read with $this->shared(), on the server and in the browser, as
     * Inertia's shared props. Sent to the browser with every page, visit and #[Server] call:
     * nothing secret.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function share(string|array $key, mixed $value = null): void
    {
        $this->part(SharedData::class)->share($key, $value);
    }

    /** The shared data, or one value of it (dot notation), with the closures called. */
    public function shared(?string $key = null, mixed $default = null): mixed
    {
        return $this->part(SharedData::class)->shared($key, $default);
    }

    /**
     * Shared data sent once (Nitro::once()), as Inertia's once props: the browser keeps it, and
     * a response neither calls the closure for the browser nor sends it while the browser has it.
     */
    public function once(Closure $value): Once
    {
        return new Once($value);
    }

    /** Share data sent once: Nitro::share($key, Nitro::once($value)). */
    public function shareOnce(string $key, Closure $value): Once
    {
        return $this->part(SharedData::class)->shareOnce($key, $value);
    }

    /**
     * Data for the next response only, as Inertia::flash(): a toast after a save.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function flash(string|array $key, mixed $value = null): void
    {
        $this->part(SharedData::class)->flash($key, $value);
    }

    /** The flash data, or one value of it (dot notation). */
    public function flashed(?string $key = null, mixed $default = null): mixed
    {
        return $this->part(SharedData::class)->flashed($key, $default);
    }

    /**
     * Forget the results #[Cached] keeps under these tags: after a save, what showed the old data.
     *
     *     Nitro::expire('students');
     */
    public function expire(string ...$tags): void
    {
        $this->part(MethodCache::class)->expire(...$tags);
    }

    /**
     * The part that does the work, as the container has it for this request.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function part(string $class): object
    {
        return $this->app->make($class);
    }
}
