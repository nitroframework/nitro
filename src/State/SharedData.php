<?php

namespace Nitro\State;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use JsonSerializable;
use Nitro\Once;
use Nitro\Support\CurrentRequest;
use Nitro\Support\Protocol;
use Nitro\Support\Wire;

/**
 * Data every component reads, beyond its own state: shared data (Nitro::share(), once data
 * Nitro::once() the browser keeps) and flash data (Nitro::flash(), for the next response only),
 * on the server and as the browser gets it. A part of Nitro.
 */
final class SharedData
{
    /** Where Nitro::flash()'s data waits in the session for the response that delivers it. */
    public const FLASH_KEY = 'nitro.flash';

    /** @var array<string, mixed> Nitro::share()'s data, closures not yet called */
    private array $shared = [];

    public function __construct(private readonly Container $app)
    {
    }

    // ------------------------------------------------------------ shared data

    /**
     * Data every component can read with $this->shared(), on the server and in the browser, as
     * Inertia's shared props: the signed-in user, flash messages. A value can be a closure,
     * called when the data is read. Keys use dot notation. The data is sent to the browser
     * with every page, nitro:navigate visit and #[Server] call: nothing secret.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function share(string|array $key, mixed $value = null): void
    {
        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            Arr::set($this->shared, (string) $name, $item);
        }
    }

    /** The shared data, or one value of it (dot notation), with the closures called. */
    public function shared(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->resolve($this->shared);

        return $key === null ? $data : data_get($data, $key, $default);
    }

    /** Share data sent once: Nitro::share($key, Nitro::once($value)). */
    public function shareOnce(string $key, Closure $value): Once
    {
        $once = new Once($value);
        $this->share($key, $once);

        return $once;
    }

    /**
     * What the browser gets of this data with a page, a visit or a #[Server] call: the shared data,
     * the once data's keys, and the flash data (delivered with it: without $flash, left for the
     * next response, as a call that redirects does).
     *
     * @return array{shared: mixed, once: object, flash: mixed}
     */
    public function forBrowser(bool $flash = true): array
    {
        return [
            'shared' => $this->sharedForBrowser(),
            'once' => (object) $this->onceForBrowser(),
            'flash' => $flash ? $this->flashForBrowser() : null,
        ];
    }

    /**
     * The shared data as the browser reads it (B.shared()), without the once data the browser
     * says it has (X-Nitro-Once), unless it is fresh.
     */
    private function sharedForBrowser(): mixed
    {
        $held = $this->heldOnce();
        $shared = array_filter($this->shared, fn (mixed $value, string|int $key) => ! ($value instanceof Once && in_array((string) $key, $held, true) && ! $value->isFresh()), ARRAY_FILTER_USE_BOTH);

        return Wire::encode($this->resolve($shared));
    }

    /**
     * The once data's keys, and when the browser asks for each again (Unix time in seconds, null
     * for never): what it keeps.
     *
     * @return array<string, int|null>
     */
    private function onceForBrowser(): array
    {
        $once = [];

        foreach ($this->shared as $key => $value) {
            if ($value instanceof Once) {
                $once[(string) $key] = $value->expiresAt();
            }
        }

        return $once;
    }

    /** @return list<string> the once data the browser has (X-Nitro-Once) */
    private function heldOnce(): array
    {
        $header = (string) CurrentRequest::of($this->app)?->headers->get(Protocol::header('once'), '');

        return array_values(array_filter(array_map('trim', explode(',', $header)), fn (string $key) => $key !== ''));
    }

    // ------------------------------------------------------------ flash data

    /**
     * Data for the next response only, as Inertia::flash(): a toast after a save. It goes with the
     * next page, visit or #[Server] answer (this request's, or the next one's after a redirect),
     * then it is gone, and the browser keeps it out of history. Read with $this->flashed().
     *
     * @param  string|array<string, mixed>  $key
     */
    public function flash(string|array $key, mixed $value = null): void
    {
        $session = CurrentRequest::session($this->app) ?? throw new \RuntimeException('Nitro::flash() keeps its data in the session: the request has none (the web middleware starts it).');
        $data = $this->flashData();

        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            Arr::set($data, (string) $name, $item);
        }

        $session->flash(self::FLASH_KEY, $data);
    }

    /** The flash data, or one value of it (dot notation). */
    public function flashed(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->flashData() : data_get($this->flashData(), $key, $default);
    }

    /**
     * The flash data for the browser (B.flashed()). Delivered, it is this request's only (the
     * session's now()): read again within it (the layout rendered for the title), gone after.
     */
    private function flashForBrowser(): mixed
    {
        $data = $this->flashData();

        $session = CurrentRequest::session($this->app);

        if ($data !== [] && $session !== null) {
            /** The session's now(): kept for this request, dropped when it ends. */
            $session->put(self::FLASH_KEY, $data);
            $session->put('_flash.old', array_values(array_unique([...(array) $session->get('_flash.old', []), self::FLASH_KEY])));
        }

        return Wire::encode($this->resolve($data));
    }

    /**
     * The flash data waiting in the session: the last request's, and this one's. Only there, not
     * on this object: a Nitro kept past its request (a facade, Octane) carries none of it over.
     *
     * @return array<string, mixed>
     */
    private function flashData(): array
    {
        return (array) (CurrentRequest::session($this->app)?->get(self::FLASH_KEY, []) ?? []);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function resolve(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof Once) {
                $value = $value->value;
            }

            if ($value instanceof Closure) {
                $value = $this->app->call($value);
            }

            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            } elseif ($value instanceof JsonSerializable) {
                $value = $value->jsonSerialize();
            }

            $values[$key] = is_array($value) ? $this->resolve($value) : $value;
        }

        return $values;
    }
}
