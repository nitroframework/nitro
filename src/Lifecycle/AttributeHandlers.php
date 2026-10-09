<?php

namespace Nitro\Lifecycle;

use Nitro\Component;
use Nitro\Feature;
use Nitro\FeatureAttribute;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use WeakMap;

/**
 * Attributes that work as features (FeatureAttribute) where they are placed: each component that
 * has them gets its own instances, and their hooks run for it: a method's calling() and called()
 * for that method only, a property's updated() when that property changed, the rest always.
 *
 * @internal
 */
final class AttributeHandlers extends Feature
{
    /** @var array<class-string, list<array{0: ReflectionAttribute<FeatureAttribute>, 1: string, 2: ?string}>> a class's attributes: [attribute, level, target] */
    private static array $found = [];

    /** @var WeakMap<Component, list<FeatureAttribute>> */
    private WeakMap $instances;

    public function __construct()
    {
        $this->instances = new WeakMap;
    }

    public function boot(Component $component): void
    {
        foreach ($this->of($component) as $attribute) {
            $attribute->boot($component);
        }
    }

    public function mounting(Component $component): void
    {
        foreach ($this->of($component) as $attribute) {
            $attribute->mounting($component);
        }
    }

    public function hydrate(Component $component): void
    {
        foreach ($this->of($component) as $attribute) {
            $attribute->hydrate($component);
        }
    }

    public function updated(Component $component, array $changed): bool
    {
        $valid = true;

        foreach ($this->of($component) as $attribute) {
            if ($attribute->level() !== 'property' || in_array($attribute->target(), $changed, true)) {
                $valid = $attribute->updated($component, $changed) && $valid;
            }
        }

        return $valid;
    }

    public function calling(Component $component, string $method, array $arguments): void
    {
        foreach ($this->of($component) as $attribute) {
            if ($attribute->level() !== 'method' || $attribute->target() === $method) {
                $attribute->calling($component, $method, $arguments);
            }
        }
    }

    public function called(Component $component, string $method, mixed $result): void
    {
        foreach ($this->of($component) as $attribute) {
            if ($attribute->level() !== 'method' || $attribute->target() === $method) {
                $attribute->called($component, $method, $result);
            }
        }
    }

    public function dehydrate(Component $component): void
    {
        foreach ($this->of($component) as $attribute) {
            $attribute->dehydrate($component);
        }
    }

    /** @return list<FeatureAttribute> the component's, made the first time it is asked for */
    private function of(Component $component): array
    {
        return $this->instances[$component] ??= array_map(
            fn (array $found) => $found[0]->newInstance()->placeOn($found[1], $found[2]),
            self::$found[$component::class] ??= self::find($component::class),
        );
    }

    /**
     * On the class, its public properties and its methods, in that order (parents' too).
     *
     * @param  class-string  $class
     * @return list<array{0: ReflectionAttribute<FeatureAttribute>, 1: string, 2: ?string}>
     */
    private static function find(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $found = [];

        foreach ($reflection->getAttributes(FeatureAttribute::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $found[] = [$attribute, 'class', null];
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            foreach ($property->getAttributes(FeatureAttribute::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $found[] = [$attribute, 'property', $property->getName()];
            }
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(FeatureAttribute::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $found[] = [$attribute, 'method', $method->getName()];
            }
        }

        return $found;
    }
}
