<?php

namespace Nitro\Fusion\Support;

use Illuminate\Support\Str;

/**
 * Names. A component, App\Fusion\Components\Students\StudentForm (namespace
 * App\Fusion\Components), is "students.student-form", placed as <fusion:students.student-form />;
 * a page, App\Fusion\Pages\Students\Index (namespace App\Fusion\Pages), is
 * "pages::students.index", as Livewire 4 names pages. Their views are the name under the
 * configured view path: "fusion.components.students.student-form", "fusion.pages.students.index".
 */
final class Naming
{
    /** What a page's name starts with. */
    public const PAGES = 'pages::';

    public static function name(string $class, string $namespace, bool $page = false): string
    {
        $relative = Str::after(ltrim($class, '\\'), trim($namespace, '\\').'\\');
        $name = implode('.', array_map(fn ($segment) => Str::kebab($segment), explode('\\', $relative)));

        return $page ? self::PAGES.$name : $name;
    }

    public static function isPage(string $name): bool
    {
        return str_starts_with($name, self::PAGES);
    }

    /** The name without the "pages::" of a page. */
    public static function bare(string $name): string
    {
        return self::isPage($name) ? substr($name, strlen(self::PAGES)) : $name;
    }

    public static function view(string $name, string $prefix): string
    {
        return $prefix === '' ? self::bare($name) : $prefix.'.'.self::bare($name);
    }

    /** "task-item" or "students.task-item": what a <fusion:...> tag may name. */
    public static function isValid(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)*$/', $name);
    }
}
