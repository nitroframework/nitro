<?php

namespace Nitro\Fusion;

use ReflectionClass;
use ReflectionProperty;

/**
 * A component's state: its public, non-static properties.
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
            fn (ReflectionProperty $p) => ! $p->isStatic()
        ));
    }
}
