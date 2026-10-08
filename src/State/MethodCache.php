<?php

namespace Nitro\Fusion\State;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;
use Nitro\Fusion\Attributes\Cached;
use Nitro\Fusion\Compiler\BrowserClasses;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\StateProperties;
use Nitro\Fusion\Component;
use Nitro\Fusion\Support\ComponentHooks;
use Nitro\Fusion\Support\CurrentRequest;
use Nitro\Fusion\Support\Wire;
use Throwable;

/**
 * Methods' results kept in the application's cache (#[Cached]): the state mount() left, what a
 * #[Server] method did (the properties it changed, what it returned). The one place Fusion reads or writes that cache.
 *
 * A result is kept by what it was worked out from: the component, the method, its code (a deploy
 * that changes it starts afresh), the arguments and the state the method saw (its #[Cached] $by properties), the user with $perUser, and the
 * version of each of its tags. Fusion::expire() moves a tag to its next version, so every result
 * kept under the old one is passed over, on any cache store (none needs to support tags).
 */
final class MethodCache
{
    private const RESULT = 'fusion.cached.';

    private const TAG = 'fusion.cached-tag.';

    public function __construct(
        private readonly Container $app,
        private readonly Repository $cache,
    ) {
    }

    /**
     * mount() as start() runs it (Features::start()): with #[Cached], the state it left for these
     * arguments and the state before it (props, route parameters, #[Url] values) when it was kept.
     *
     * @param  array<array-key, mixed>  $arguments  mount()'s
     * @param  Closure(): void  $mount
     * @return Closure(): void
     */
    public function mount(Component $component, ComponentMeta $meta, array $arguments, Closure $mount): Closure
    {
        $rule = $meta->cached[ComponentHooks::MOUNT] ?? null;

        if ($rule === null) {
            return $mount;
        }

        return function () use ($component, $meta, $arguments, $mount, $rule): void {
            $key = $this->key($meta, ComponentHooks::MOUNT, [$arguments, $this->seen(StateProperties::values($component), $rule)], $rule);
            $kept = $key === null ? null : $this->cache->get($key);

            if (is_array($kept)) {
                foreach ($kept as $property => $value) {
                    $component->{$property} = $value;
                }

                return;
            }

            $mount();

            if ($key !== null) {
                $this->put($key, StateProperties::values($component), $rule, "{$meta->class}::mount()");
            }
        };
    }

    /**
     * A #[Server] method's call: with #[Cached], what it did for these arguments and this state
     * (the properties it changed, what it returned), done again without running it.
     *
     * @param  array<array-key, mixed>  $arguments
     * @param  Closure(): mixed  $call
     */
    public function call(Component $component, ComponentMeta $meta, string $method, array $arguments, Closure $call): mixed
    {
        $rule = $meta->cached[$method] ?? null;

        if ($rule === null) {
            return $call();
        }

        $before = StateProperties::values($component);
        $key = $this->key($meta, $method, [$arguments, $this->seen($before, $rule)], $rule);
        $kept = $key === null ? null : $this->cache->get($key);

        if (is_array($kept) && is_array($kept['changed'] ?? null) && array_key_exists('returned', $kept)) {
            foreach ($kept['changed'] as $property => $value) {
                $component->{$property} = $value;
            }

            return $kept['returned'];
        }

        $returned = $call();

        if ($key !== null) {
            $changed = $this->changed($before, StateProperties::values($component));
            $this->put($key, ['changed' => $changed, 'returned' => $returned], $rule, "{$meta->class}::{$method}()");
        }

        return $returned;
    }

    /**
     * The state a result depends on: $by's properties, or all of it.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function seen(array $state, Cached $rule): array
    {
        return $rule->by === null ? $state : array_intersect_key($state, array_flip($rule->by));
    }

    /**
     * The properties whose value is not what it was.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function changed(array $before, array $after): array
    {
        return array_filter(
            $after,
            fn (mixed $value, string $property) => ! array_key_exists($property, $before)
                || $this->fingerprint([$value]) === null
                || $this->fingerprint([$value]) !== $this->fingerprint([$before[$property]]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Every result kept under one of these tags is passed over from now on. */
    public function expire(string ...$tags): void
    {
        foreach ($tags as $tag) {
            $this->cache->forever(self::TAG.$tag, $this->version($tag) + 1);
        }
    }

    /**
     * The key of a result; null when what it was worked out from cannot be told apart (a value
     * that is not state, such as an object Fusion cannot send): then it is not kept.
     *
     * @param  array<array-key, mixed>  $from
     */
    private function key(ComponentMeta $meta, string $method, array $from, Cached $rule): ?string
    {
        $fingerprint = $this->fingerprint($from);

        if ($fingerprint === null) {
            return null;
        }

        $tags = [];

        foreach ($rule->tags as $tag) {
            $tags[$tag] = $this->version($tag);
        }

        $user = $rule->perUser ? CurrentRequest::of($this->app)?->user()?->getAuthIdentifier() : null;

        return self::RESULT.md5(serialize([
            $meta->class,
            $method,
            $this->code($meta->class),
            $user,
            $tags,
            $fingerprint,
        ]));
    }

    /**
     * The component's code as it is now: its class files (its parents', its traits') and when each
     * was changed. A deploy that changes them starts afresh.
     *
     * @param  class-string  $class
     */
    private function code(string $class): string
    {
        $files = BrowserClasses::files($class);

        return md5(implode('|', array_map(fn (string $file) => $file.':'.@filemtime($file), $files)));
    }

    private function version(string $tag): int
    {
        return (int) $this->cache->get(self::TAG.$tag, 0);
    }

    /** @param  array<array-key, mixed>  $values */
    private function fingerprint(array $values): ?string
    {
        try {
            return md5(Wire::json($values));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function put(string $key, mixed $value, Cached $rule, string $where): void
    {
        try {
            $this->cache->put($key, $value, $rule->seconds);
        } catch (Throwable $exception) {
            throw new LogicException("#[Cached] on {$where}: its result cannot be kept in the cache ({$exception->getMessage()}).", previous: $exception);
        }
    }
}
