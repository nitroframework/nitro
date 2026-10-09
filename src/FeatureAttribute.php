<?php

namespace Nitro;

/**
 * An attribute that works as a feature where it is placed: on a component's class, on one of its
 * properties or on one of its #[Server] methods. Nitro runs its hooks (Feature's) for the
 * components that have it, after the component's own and before #[Validate] and the features
 * listed in nitro.features (so a property's updated() can tidy a value before it is validated):
 *
 *  - on a method: calling() and called() for that method only (a #[Server] one: a browser
 *    method never reaches the server, so the attribute there fails the build)
 *  - on a property: updated() only when the browser changed that property
 *  - boot(), mounting(), hydrate(), dehydrate(): wherever it is
 *
 * target() is the property or method it is on (null on the class). An instance is made for each
 * component that has it, from the attribute's arguments; it is not made by the container.
 *
 *     #[Attribute(Attribute::TARGET_METHOD)]
 *     class Throttle extends FeatureAttribute
 *     {
 *         public function __construct(public int $perMinute = 10) {}
 *
 *         public function calling(Component $component, string $method, array $arguments): void
 *         {
 *             $key = 'nitro:'.$component::class.':'.$method.':'.request()->ip();
 *             abort_if(RateLimiter::tooManyAttempts($key, $this->perMinute), 429);
 *             RateLimiter::hit($key);
 *         }
 *     }
 */
abstract class FeatureAttribute extends Feature
{
    /** 'class', 'property' or 'method' */
    private string $level = 'class';

    private ?string $target = null;

    /** The property or method the attribute is on; null on the class. */
    public function target(): ?string
    {
        return $this->target;
    }

    /**
     * What it is on: 'class', 'property' or 'method'.
     *
     * @internal
     */
    public function level(): string
    {
        return $this->level;
    }

    /**
     * Where Nitro found it.
     *
     * @internal
     */
    public function placeOn(string $level, ?string $target): static
    {
        $this->level = $level;
        $this->target = $target;

        return $this;
    }
}
