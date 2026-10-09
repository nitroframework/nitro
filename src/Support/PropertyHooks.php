<?php

namespace Nitro\Support;

use Illuminate\Support\Str;

/**
 * The property hooks: what runs when the view changes a property (nitro:model, $set(), a
 * JavaScript view's set() or binding). Not when a method assigns one: the method knows what it
 * changed. The one place their names are read; the compiler gives the browser each component's
 * hooks by property, so the runtime never builds a hook's name itself.
 *
 *   updatingEmail($value, $key)     before $email changes: $this->email still has the old value
 *   updating($property, $value)     before any change, with its path ("form.email")
 *   updatedEmail($value, $key)      after $email changed
 *   updated($property, $value)      after any change, with its path
 *
 * $key is the path under the property for a change deep inside it ("email" for form.email), null
 * for the property itself. A hook names its property in StudlyCase: updatedFirstName() is
 * $firstName's, or $first_name's. A method whose name names no property is not a hook.
 *
 * They run in the browser, compiled. An updated hook may be #[Server]: the browser makes the
 * change, then calls it, sending the change along. An updating hook runs before the change is
 * made, so it is never #[Server].
 */
final class PropertyHooks
{
    public const BEFORE = 'updating';

    public const AFTER = 'updated';

    /**
     * The hook a method is: when it runs, and the property it is for (null for every property).
     * Null when the method is not one.
     *
     * @param  list<string>  $properties  the component's public properties
     * @return array{when: string, property: ?string}|null
     */
    public static function parse(string $method, array $properties): ?array
    {
        foreach ([self::BEFORE, self::AFTER] as $when) {
            if ($method === $when) {
                return ['when' => $when, 'property' => null];
            }

            if (str_starts_with($method, $when) && ctype_upper($method[strlen($when)] ?? '')) {
                $studly = substr($method, strlen($when));

                foreach ($properties as $property) {
                    if (Str::studly($property) === $studly) {
                        return ['when' => $when, 'property' => $property];
                    }
                }

                return null;
            }
        }

        return null;
    }

    /** updating() and updated(): their first argument is a property's path, as the browser names it. */
    public static function takesPath(string $method): bool
    {
        return $method === self::BEFORE || $method === self::AFTER;
    }

    /** Why the hook cannot be #[Server]; null when it may be. */
    public static function whyNotServer(string $method, array $properties): ?string
    {
        if ((self::parse($method, $properties)['when'] ?? null) !== self::BEFORE) {
            return null;
        }

        return "{$method}() runs before the change is made, which the browser cannot hold up for a request: use an updated hook, which may be #[Server]";
    }
}
