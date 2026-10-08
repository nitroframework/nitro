<?php

namespace Nitro\Fusion\View\Adapters;

/**
 * Fusion's JavaScript adapters share one core (js/adapters/core.js), which gives every view
 * set(), upload(), errors and the rest besides the component's own names. Their list is
 * js/adapters/names.json, which the core reads too.
 */
trait SharedCore
{
    /** @var list<string>|null */
    private static ?array $sharedNames = null;

    /** @return list<string> */
    public function reservedNames(): array
    {
        if (self::$sharedNames === null) {
            $names = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/js/adapters/names.json'), true, flags: JSON_THROW_ON_ERROR);
            self::$sharedNames = [...$names['functions'], ...$names['values']];
        }

        return self::$sharedNames;
    }
}
