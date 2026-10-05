<?php

namespace Nitro\Fusion;

use FilesystemIterator;
use Nitro\Fusion\Support\Naming;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Component and page names and classes: discovered in the configured directories
 * (App\Fusion\Components and App\Fusion\Pages by default), or registered by hand. Pages are
 * named "pages::...". `fusion:build` caches the list, with the times its directories last changed:
 * a file added, removed or renamed since (a component made after a build) and it is found again.
 */
final class Registry
{
    /** @var array<string, class-string<Component>> name => class */
    private array $components = [];

    private bool $discovered = false;

    /**
     * @param  array{namespace: string, path: string, views: string}  $componentsAt  where components are
     * @param  array{namespace: string, path: string, views: string}  $pagesAt  where pages are
     */
    public function __construct(
        private readonly array $componentsAt,
        private readonly array $pagesAt,
        private readonly ?string $cache = null,
    ) {
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

        foreach ([[$this->pagesAt, true], [$this->componentsAt, false]] as [$location, $page]) {
            if (str_starts_with($class, trim($location['namespace'], '\\').'\\')) {
                return Naming::name($class, $location['namespace'], $page);
            }
        }

        return Naming::name($class, substr($class, 0, (int) strrpos($class, '\\')));
    }

    /** Whether a class is a page, from its namespace (no discovery: Route::fusion() asks while routes load). */
    public function isPageClass(string $class): bool
    {
        $class = ltrim($class, '\\');

        if (in_array($class, $this->components, true)) {
            return Naming::isPage((string) array_search($class, $this->components, true));
        }

        return str_starts_with($class, trim($this->pagesAt['namespace'], '\\').'\\');
    }

    /** The view a component or page renders, from its name. */
    public function view(string $name): string
    {
        return Naming::view($name, (Naming::isPage($name) ? $this->pagesAt : $this->componentsAt)['views']);
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

                return;
            }
        }

        foreach ([[$this->componentsAt, false], [$this->pagesAt, true]] as [$location, $page]) {
            if (! is_dir($location['path'])) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($location['path'], FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen(rtrim($location['path'], '\\/')) + 1, -4);
                $class = trim($location['namespace'], '\\').'\\'.str_replace(['/', '\\'], '\\', $relative);

                if (class_exists($class) && is_subclass_of($class, Component::class) && (new ReflectionClass($class))->isInstantiable()) {
                    $this->components[Naming::name($class, $location['namespace'], $page)] ??= $class;
                }
            }
        }
    }

    /** The list for bootstrap/cache (written by fusion:build), and when its directories last changed. */
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

        foreach ([$this->componentsAt['path'], $this->pagesAt['path']] as $path) {
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
