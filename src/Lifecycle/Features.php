<?php

namespace Nitro\Lifecycle;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nitro\Component;
use Nitro\Feature;
use Nitro\Support\ComponentHooks;
use Throwable;

/**
 * The server lifecycle of components: each hook runs the component's own method of that name
 * (boot(), hydrate(), dehydrate(), when it has one), then every feature's: Nitro's own (OWN),
 * then the application's (nitro.features), in order. Bound once per request.
 *
 * @internal
 */
final class Features
{
    /** Nitro's own, first: the attributes that work as features (#[Url] among them), then #[Validate]. */
    public const OWN = [AttributeHandlers::class, Validation::class];

    /** @var list<Feature>|null made on first use */
    private ?array $features = null;

    /**
     * @param  array<mixed>  $classes  the application's (nitro.features)
     */
    public function __construct(
        private readonly Container $app,
        private readonly array $classes = [],
    ) {
    }

    /** A server hook (Support\ComponentHooks), when the component has it; what it returns. */
    public static function hook(Component $component, string $hook, mixed ...$arguments): mixed
    {
        return method_exists($component, $hook) ? $component->{$hook}(...$arguments) : null;
    }

    /**
     * A new instance (a component, a page, a store), started in the one order every kind keeps:
     * its id and name, boot, its inputs (props, a route's parameters), the features' mounting (a
     * #[Url] value over an input), then mount() when it has one.
     *
     * @param  (Closure(): void)|null  $inputs  sets what it was given
     * @param  (Closure(): void)|null  $mount  calls its mount() with what it was given
     */
    public function start(Component $component, string $id, string $name, ?Closure $inputs = null, ?Closure $mount = null): void
    {
        $component->nitro()->id = $id;
        $component->nitro()->name = $name;
        $this->boot($component);

        if ($inputs !== null) {
            $inputs();
        }

        foreach ($this->features() as $feature) {
            $feature->mounting($component);
        }

        if ($mount !== null) {
            $mount();
        }
    }

    public function boot(Component $component): void
    {
        self::hook($component, ComponentHooks::BOOT);

        foreach ($this->features() as $feature) {
            $feature->boot($component);
        }
    }

    public function hydrate(Component $component): void
    {
        self::hook($component, ComponentHooks::HYDRATE);

        foreach ($this->features() as $feature) {
            $feature->hydrate($component);
        }
    }

    /**
     * Whether the method may run: false when any feature says not. Every feature hears it either way.
     *
     * @param  list<string>  $changed
     */
    public function updated(Component $component, array $changed): bool
    {
        $valid = true;

        foreach ($this->features() as $feature) {
            $valid = $feature->updated($component, $changed) && $valid;
        }

        return $valid;
    }

    /** @param  list<mixed>  $arguments */
    public function calling(Component $component, string $method, array $arguments): void
    {
        foreach ($this->features() as $feature) {
            $feature->calling($component, $method, $arguments);
        }
    }

    /**
     * The method, through every feature's call(): the first listed outermost, the method itself
     * ($method) innermost.
     *
     * @param  list<mixed>  $arguments
     * @param  Closure(): mixed  $method
     */
    public function call(Component $component, string $name, array $arguments, Closure $method): mixed
    {
        $run = $method;

        foreach (array_reverse($this->features()) as $feature) {
            $inner = $run;
            $run = fn () => $feature->call($component, $name, $arguments, $inner);
        }

        return $run();
    }

    public function called(Component $component, string $method, mixed $result): void
    {
        foreach ($this->features() as $feature) {
            $feature->called($component, $method, $result);
        }
    }

    /** Whether a feature handled what the method threw; the first that does, the last to hear it. */
    public function exception(Component $component, string $method, Throwable $exception): bool
    {
        foreach ($this->features() as $feature) {
            if ($feature->exception($component, $method, $exception)) {
                return true;
            }
        }

        return false;
    }

    /** Once per request (a visit may take a component's snapshot twice). */
    public function dehydrate(Component $component): void
    {
        $context = $component->nitro();

        if ($context->dehydrated) {
            return;
        }

        $context->dehydrated = true;
        self::hook($component, ComponentHooks::DEHYDRATE);

        foreach ($this->features() as $feature) {
            $feature->dehydrate($component);
        }
    }

    /** @return list<Feature> */
    private function features(): array
    {
        return $this->features ??= array_map(function (mixed $class): Feature {
            if (! is_string($class) || ! is_subclass_of($class, Feature::class)) {
                throw new InvalidArgumentException('nitro.features: '.(is_string($class) ? $class : get_debug_type($class)).' is not a feature (a class extending '.Feature::class.').');
            }

            /** @var Feature */
            return $this->app->make($class);
        }, array_values(array_unique([...self::OWN, ...$this->classes], SORT_REGULAR)));
    }
}
