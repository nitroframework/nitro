<?php

namespace Nitro\Fusion;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * A component's state: its public, non-static properties, but those holding a store (a store's
 * state is its own, shared by the components that use it).
 */
final class State
{
    /** @return array<string, mixed> */
    public static function of(Component $component): array
    {
        $state = [];

        foreach (self::properties($component::class) as $property) {
            if ($property->isInitialized($component)) {
                $state[$property->getName()] = $property->getValue($component);
            }
        }

        return $state;
    }

    /** @return list<ReflectionProperty> */
    public static function properties(string $class): array
    {
        return array_values(array_filter(
            (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $p) => ! $p->isStatic() && self::store($p) === null
        ));
    }

    /**
     * The stores a component uses: its public properties typed with a store class.
     *
     * @return array<string, class-string<Store>> property => store class
     */
    public static function stores(string $class): array
    {
        $stores = [];

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic() && ($store = self::store($property)) !== null) {
                $stores[$property->getName()] = $store;
            }
        }

        return $stores;
    }

    /** The store class a property is typed with (public Cart $cart), or null. */
    private static function store(ReflectionProperty $property): ?string
    {
        $type = $property->getType();

        return $type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), Store::class)
            ? $type->getName()
            : null;
    }
}
