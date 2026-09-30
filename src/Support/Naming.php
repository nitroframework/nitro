<?php

namespace Nitro\Fusion\Support;

use Illuminate\Support\Str;

/**
 * Component names: App\Fusion\Students\StudentForm (namespace App\Fusion) is
 * "students.student-form", and its view is "fusion.students.student-form".
 */
final class Naming
{
    public static function name(string $class, string $namespace): string
    {
        $relative = Str::after(ltrim($class, '\\'), trim($namespace, '\\').'\\');

        return implode('.', array_map(fn ($segment) => Str::kebab($segment), explode('\\', $relative)));
    }

    public static function view(string $name, string $prefix = 'fusion'): string
    {
        return $prefix === '' ? $name : $prefix.'.'.$name;
    }

    /** "task-item" or "students.task-item": what a <fusion:...> tag may name. */
    public static function isValid(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)*$/', $name);
    }
}
