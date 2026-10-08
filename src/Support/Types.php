<?php

namespace Nitro\Fusion\Support;

/**
 * PHP's scalar types as Fusion takes values from the browser: the list, and whether a value
 * fits one. No coercion: a property or a #[Server] parameter takes a value of its own type
 * (an int is accepted for a float, which JSON cannot tell apart).
 */
final class Types
{
    /** The types a value from the browser can have. */
    public const SCALARS = ['int', 'float', 'string', 'bool', 'array'];

    /** Whether the value fits the type ('mixed': any scalar or array). */
    public static function fits(string $type, mixed $value): bool
    {
        return match ($type) {
            'mixed' => is_scalar($value) || is_array($value),
            'int' => is_int($value),
            'float' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => false,
        };
    }
}
