<?php

namespace Nitro\Support;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use LogicException;
use Nitro\Component;
use Nitro\Store;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Component and page names and classes: discovered in the configured directories
 * (App\Nitro\Components and App\Nitro\Pages by default, and each namespace's of
 * nitro.namespaces), or registered by hand. Pages are named "pages::...", a namespace's
 * components "issues::...". `nitro:build` caches the list, with the times its directories last
 * changed: a file added, removed or renamed since (a component made after a build) and it is
 * found again.
 */
final class Registry
{
    /** Where `nitro:build` caches the list, under the app's bootstrap folder. */
    public const CACHE = 'cache/nitro.php';

    /** @var array<string, class-string<Component>> name => class */
    private array $components = [];

    private bool $discovered = false;

    /**
     * Where components, pages and stores are, each with its kind and the namespace that owns it (null: the
     * app's): the one list every question about a place asks.
     *
     * @var list<array{kind: 'component'|'page'|'store', owner: ?string, at: array{namespace: string, path: string, views?: string}}>
     */
    private readonly array $locations;

    /**
     * @param  array{namespace: string, path: string, views: string}  $componentsAt  where components are
     * @param  array{namespace: string, path: string, views: string}  $pagesAt  where pages are
     * @param  array{namespace: string, path: string}|null  $storesAt  where stores are
     * @param  array<string, array{components?: array{namespace: string, path: string, views?: string}, pages?: array{namespace: string, path: string, views?: string}, stores?: array{namespace: string, path: string}}>  $namespaces  each namespace's, by its name (nitro.namespaces)
     */
    public function __construct(
        array $componentsAt,
        array $pagesAt,
        private readonly ?string $cache = null,
        ?array $storesAt = null,
        /** @var (Closure(): array<string, class-string<Component>>)|null components written in their views (ViewColocatedViews) */
        private readonly ?Closure $colocated = null,
        array $namespaces = [],
    ) {
        $locations = [
            ['kind' => 'component', 'owner' => null, 'at' => $componentsAt],
            ['kind' => 'page', 'owner' => null, 'at' => $pagesAt],
            ...($storesAt === null ? [] : [['kind' => 'store', 'owner' => null, 'at' => $storesAt]]),
        ];

        foreach ($namespaces as $in => $places) {
            if (! Naming::isNamespace((string) $in)) {
                throw new InvalidArgumentException("nitro.namespaces: [{$in}] cannot name a namespace. A namespace's name is kebab-case (issues, billing-admin), and not pages or stores.");
            }

            foreach (['components' => 'component', 'pages' => 'page', 'stores' => 'store'] as $key => $kind) {
                if (isset($places[$key])) {
                    $locations[] = ['kind' => $kind, 'owner' => (string) $in, 'at' => $places[$key]];
                }
            }
        }

        $this->locations = $locations;
    }

    /**
     * A component or page by hand: a page's name starts with "pages::".
     *
     * @param  class-string<Component>  $class
     */
    public function register(string $name, string $class): void
    {
        $this->components[$name] = $class;
    }

    /** @return array<string, class-string<Component>> */
    public function all(): array
    {
        $this->discover();

        return $this->components;
    }

    public function class(string $name): ?string
    {
        return $this->all()[$name] ?? null;
    }

    public function name(string $class): string
    {
        $class = ltrim($class, '\\');
        $name = array_search($class, $this->all(), true);

        if ($name !== false) {
            return $name;
        }

        $location = $this->locationOf($class);

        if ($location !== null) {
            return Naming::name($class, $location['at']['namespace'], $location['kind'] === 'page', $location['kind'] === 'store', $location['owner']);
        }

        return Naming::name($class, substr($class, 0, (int) strrpos($class, '\\')));
    }

    /** Whether a class is a page, from its namespace (no discovery: Route::nitro() asks while routes load). */
    public function isPageClass(string $class): bool
    {
        $class = ltrim($class, '\\');

        if (in_array($class, $this->components, true)) {
            return Naming::isPage((string) array_search($class, $this->components, true));
        }

        return ($this->locationOf($class)['kind'] ?? null) === 'page';
    }

    /** Whether a class is a store (it extends Store). */
    public function isStoreClass(string $class): bool
    {
        return is_subclass_of(ltrim($class, '\\'), Store::class);
    }

    /**
     * The view a component or page renders, from its name ("" for a store, which has none). A
     * namespace that names no views for its kind has them in the Blade namespace of its name:
     * "issues::comment-box".
     */
    public function view(string $name): string
    {
        if (Naming::isStore($name)) {
            return '';
        }

        $in = Naming::namespaceOf($name);
        $location = $this->location(Naming::isPage($name) ? 'page' : 'component', $in);

        return Naming::view($name, $location['views'] ?? ($in === null ? '' : Naming::within($in, '')));
    }

    /**
     * Where a new component, page or store goes (make:nitro): its class, from its folders and
     * name (['Posts', 'Index']) under the namespace configured for its kind, and its file.
     *
     * @param  'component'|'page'|'store'  $kind
     * @param  non-empty-list<string>  $segments
     * @param  string|null  $in  a namespace of nitro.namespaces; null: the app's
     * @return array{0: string, 1: string} the class, and its file
     */
    public function placeOf(string $kind, array $segments, ?string $in = null): array
    {
        $location = $this->location($kind, $in) ?? throw new LogicException($in === null
            ? 'No place for stores is configured (nitro.stores).'
            : "No place for {$kind}s of [{$in}] is configured (nitro.namespaces.{$in}).");

        return [
            trim($location['namespace'], '\\').'\\'.implode('\\', $segments),
            rtrim($location['path'], '/\\').DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments).'.php',
        ];
    }

    /** Whether nitro.namespaces has this namespace. */
    public function hasNamespace(string $in): bool
    {
        return in_array($in, array_column($this->locations, 'owner'), true);
    }

    /**
     * Where a kind of a namespace is (null: the app's); null when none is configured.
     *
     * @param  'component'|'page'|'store'  $kind
     * @return array{namespace: string, path: string, views?: string}|null
     */
    private function location(string $kind, ?string $in = null): ?array
    {
        foreach ($this->locations as $location) {
            if ($location['kind'] === $kind && $location['owner'] === $in) {
                return $location['at'];
            }
        }

        return null;
    }

    /**
     * The location a class is in: of those whose PHP namespace holds it, the most specific, so
     * a namespace's pages in RepoHub\Issues\Ui\Pages are pages, not components of its
     * RepoHub\Issues\Ui. A store is in a place for stores, any other class in another (stores
     * may share a folder with components).
     *
     * @return array{kind: 'component'|'page'|'store', owner: ?string, at: array{namespace: string, path: string, views?: string}}|null
     */
    private function locationOf(string $class): ?array
    {
        $store = $this->isStoreClass($class);
        $found = null;
        $length = -1;

        foreach ($this->locations as $location) {
            $namespace = trim($location['at']['namespace'], '\\').'\\';

            if (($location['kind'] === 'store') === $store && str_starts_with($class, $namespace) && strlen($namespace) > $length) {
                [$found, $length] = [$location, strlen($namespace)];
            }
        }

        return $found;
    }

    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $this->discovered = true;

        if ($this->cache !== null && is_file($this->cache)) {
            $cached = require $this->cache;

            if (is_array($cached) && isset($cached['components'], $cached['directories']) && $cached['directories'] === $this->directories()) {
                $this->components += $cached['components'];
                $this->components += $this->colocated === null ? [] : ($this->colocated)();

                return;
            }
        }

        foreach ($this->locations as $location) {
            ['kind' => $kind, 'owner' => $in, 'at' => $at] = $location;

            if (! is_dir($at['path'])) {
                continue;
            }

            $store = $kind === 'store';
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($at['path'], FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen(rtrim($at['path'], '\\/')) + 1, -4);
                $class = trim($at['namespace'], '\\').'\\'.str_replace(['/', '\\'], '\\', $relative);

                /** A store only where stores are, and only a store there; a class in a location inside this one is that one's. */
                if (class_exists($class) && is_subclass_of($class, Component::class) && is_subclass_of($class, Store::class) === $store && (new ReflectionClass($class))->isInstantiable()
                    && $this->locationOf($class) === $location) {
                    $this->components[Naming::name($class, $at['namespace'], $kind === 'page', $store, $in)] ??= $class;
                }
            }
        }

        /** Those written in their views (their classes made as their views change). */
        $this->components += $this->colocated === null ? [] : ($this->colocated)();
    }

    /** The list for bootstrap/cache (written by nitro:build), and when its directories last changed. */
    public function export(): string
    {
        return '<?php return '.var_export(['components' => $this->all(), 'directories' => $this->directories()], true).';'.PHP_EOL;
    }

    /**
     * The component and page directories, each with when it last changed (adding, removing or
     * renaming a file in it changes it).
     *
     * @return array<string, int>
     */
    private function directories(): array
    {
        $directories = [];

        foreach (array_unique(array_filter(array_column(array_column($this->locations, 'at'), 'path'))) as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $directories[$path] = (int) filemtime($path);
            $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

            foreach ($tree as $entry) {
                if ($entry->isDir()) {
                    $directories[$entry->getPathname()] = $entry->getMTime();
                }
            }
        }

        return $directories;
    }
}
