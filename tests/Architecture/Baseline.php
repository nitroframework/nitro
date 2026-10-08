<?php

namespace Nitro\Fusion\Tests\Architecture;

/** The known breaks (known-breaks.php): by guard, then rule, then file => the TASKS item that fixes it. */
final class Baseline
{
    /** @var array<string, array<string, array<string, string>>>|null */
    private static ?array $entries = null;

    /** @return array<string, string> file => the TASKS item */
    public static function of(string $guard, string $rule): array
    {
        self::$entries ??= require __DIR__.'/known-breaks.php';

        return self::$entries[$guard][$rule] ?? [];
    }
}
