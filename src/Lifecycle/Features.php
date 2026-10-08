<?php

namespace Nitro\Fusion\Lifecycle;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nitro\Fusion\Component;
use Nitro\Fusion\Feature;
use Nitro\Fusion\Support\ComponentHooks;

/**
 * The server lifecycle of components: each hook runs the component's own method of that name
 * (boot(), hydrate(), dehydrate(), when it has one), then every feature's: Fusion's own (OWN),
 * then the application's (fusion.features), in order. Bound once per request.
 *
 * @internal
 */
final class Features
{
    /** Fusion's own, first: the attributes that work as features (#[Url] among them), then #[Validate]. */
    public const OWN = [AttributeHandlers::class, Validation::class];

    /** @var list<Feature>|null made on first use */
    private ?array $features = null;

    /**
     * @param  array<mixed>  $classes  the application's (fusion.features)
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

    public function boot(Component $component): void
    {
        self::hook($component, ComponentHooks::BOOT);

        foreach ($this->features() as $feature) {
            $feature->boot($component);
        }
    }

    public function mounting(Component $component): void
    {
        foreach ($this->features() as $feature) {
            $feature->mounting($component);
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

    public function called(Component $component, string $method, mixed $result): void
    {
        foreach ($this->features() as $feature) {
            $feature->called($component, $method, $result);
        }
    }

    /** Once per request (a visit may take a component's snapshot twice). */
    public function dehydrate(Component $component): void
    {
        $context = $component->fusion();

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
                throw new InvalidArgumentException('fusion.features: '.(is_string($class) ? $class : get_debug_type($class)).' is not a feature (a class extending '.Feature::class.').');
            }

            /** @var Feature */
            return $this->app->make($class);
        }, array_values(array_unique([...self::OWN, ...$this->classes], SORT_REGULAR)));
    }
}
