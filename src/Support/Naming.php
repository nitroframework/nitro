<?php

namespace Nitro\Support;

use Illuminate\Support\Str;

/**
 * Names. A component, App\Nitro\Components\Posts\CommentForm (namespace
 * App\Nitro\Components), is "posts.comment-form", placed as <nitro:posts.comment-form />;
 * a page, App\Nitro\Pages\Posts\Index (namespace App\Nitro\Pages), is
 * "pages::posts.index", as Livewire 4 names pages. Their views are the name under the
 * configured view path: "nitro.components.posts.comment-form", "nitro.pages.posts.index".
 */
final class Naming
{
    /** What a page's name starts with. */
    public const PAGES = 'pages::';

    /** What a store's name starts with: App\Nitro\Stores\Cart is "stores::cart"; a store has no view. */
    public const STORES = 'stores::';

    public static function name(string $class, string $namespace, bool $page = false, bool $store = false): string
    {
        $relative = Str::after(ltrim($class, '\\'), trim($namespace, '\\').'\\');
        $name = implode('.', array_map(fn ($segment) => Str::kebab($segment), explode('\\', $relative)));

        return ($page ? self::PAGES : ($store ? self::STORES : '')).$name;
    }

    public static function isStore(string $name): bool
    {
        return str_starts_with($name, self::STORES);
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

    /** "post-card" or "posts.post-card": what a <nitro:...> tag may name. */
    public static function isValid(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)*$/', $name);
    }
}
