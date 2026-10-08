<?php

namespace Nitro\Fusion\Compiler;

use Nitro\Fusion\Component;
use Nitro\Fusion\Store;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Which of a component's properties are its state, read from its class: its public, non-static
 * properties, but those holding a store (a store's state is its own, shared by the components
 * that use it). And their values on an instance.
 */
final class StateProperties
{
    /**
     * The component's state: its state properties' values (those set).
     *
     * @return array<string, mixed>
     */
    public static function values(Component $component): array
    {
        $state = [];

        foreach (self::of($component::class) as $property) {
            if ($property->isInitialized($component)) {
                $state[$property->getName()] = $property->getValue($component);
            }
        }

        return $state;
    }

    /** @return list<ReflectionProperty> */
    public static function of(string $class): array
    {
        return array_values(array_filter(
            (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $property) => ! $property->isStatic() && self::store($property) === null
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
