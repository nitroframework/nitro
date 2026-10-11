<?php

namespace Nitro\Support;

use Illuminate\Support\Str;

/**
 * Names. A component, App\Nitro\Components\Posts\CommentForm (namespace
 * App\Nitro\Components), is "posts.comment-form", placed as <nitro:posts.comment-form />;
 * a page, App\Nitro\Pages\Posts\Index (namespace App\Nitro\Pages), is
 * "pages::posts.index", as Livewire 4 names pages. Their views are the name under the
 * configured view path: "nitro.components.posts.comment-form", "nitro.pages.posts.index".
 *
 * A component of a namespace (nitro.namespaces: a module's, a package's) carries it:
 * "issues::comment-box", placed as <nitro:issues::comment-box />; its page is
 * "pages::issues::list", its store "stores::issues::cart".
 */
final class Naming
{
    /** What a page's name starts with. */
    public const PAGES = 'pages::';

    /** What a store's name starts with: App\Nitro\Stores\Cart is "stores::cart"; a store has no view. */
    public const STORES = 'stores::';

    /** What ends a namespace in a name: "issues::comment-box"; js/protocol.json's view.namespaceSeparator, which the adapters read. */
    public const SEPARATOR = '::';

    /** A namespace's name, as nitro.namespaces keys it: "issues", "billing-admin". */
    public const NAMESPACE = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public static function name(string $class, string $namespace, bool $page = false, bool $store = false, ?string $in = null): string
    {
        $relative = Str::after(ltrim($class, '\\'), trim($namespace, '\\').'\\');
        $name = implode('.', array_map(fn ($segment) => Str::kebab($segment), explode('\\', $relative)));

        return ($page ? self::PAGES : ($store ? self::STORES : '')).self::within($in, $name);
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

    /** The namespace a name is in: "issues" for "issues::comment-box" and "pages::issues::list"; null for the app's own. */
    public static function namespaceOf(string $name): ?string
    {
        return self::split(self::withoutKind($name))[0];
    }

    /** The name within its namespace, without "pages::" or "stores::": "comment-box", "posts.index". */
    public static function local(string $name): string
    {
        return self::split(self::withoutKind($name))[1];
    }

    /**
     * A view's or a component's name and its namespace: ["issues", "list"] for "issues::list";
     * [null, "list"] for the app's own.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function split(string $name): array
    {
        return str_contains($name, self::SEPARATOR) ? [strstr($name, self::SEPARATOR, true), substr($name, strpos($name, self::SEPARATOR) + strlen(self::SEPARATOR))] : [null, $name];
    }

    /** A name in a namespace: "issues::list"; the name as it is in the app's (null). */
    public static function within(?string $namespace, string $name): string
    {
        return $namespace === null ? $name : $namespace.self::SEPARATOR.$name;
    }

    /** A page's chunk unless it names one: "posts.index", "issues.list" (a chunk's name holds no "::"). */
    public static function group(string $name): string
    {
        return str_replace(self::SEPARATOR, '.', self::bare($name));
    }

    /**
     * Its view under $prefix: "nitro.components.posts.comment-form", or in a Blade namespace,
     * "issues::components.comment-box" ("issues::" alone: "issues::comment-box").
     */
    public static function view(string $name, string $prefix): string
    {
        return $prefix === '' || str_ends_with($prefix, self::SEPARATOR) ? $prefix.self::local($name) : $prefix.'.'.self::local($name);
    }

    /** Whether a namespace may be named so: kebab-case, and not a kind's ("pages", "stores"). */
    public static function isNamespace(string $name): bool
    {
        return preg_match('/^'.self::NAMESPACE.'$/', $name) === 1 && ! in_array($name.self::SEPARATOR, [self::PAGES, self::STORES], true);
    }

    /** "post-card", "posts.post-card" or "issues::post-card": what a <nitro:...> tag may name. */
    public static function isValid(string $name): bool
    {
        return (bool) preg_match('/^(?:'.self::NAMESPACE.'::)?[a-z0-9]+(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)*$/', $name);
    }

    private static function withoutKind(string $name): string
    {
        foreach ([self::PAGES, self::STORES] as $kind) {
            if (str_starts_with($name, $kind)) {
                return substr($name, strlen($kind));
            }
        }

        return $name;
    }
}
