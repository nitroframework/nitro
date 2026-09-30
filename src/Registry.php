<?php

namespace Nitro\Fusion;

use FilesystemIterator;
use Nitro\Fusion\Support\Naming;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Component names and classes: discovered in the configured directories (App\Fusion by
 * default), or registered by hand. `fusion:build` caches the list.
 */
final class Registry
{
    /** @var array<string, class-string<Component>> name => class */
    private array $components = [];

    private bool $discovered = false;

    /**
     * @param  list<array{namespace: string, path: string}>  $locations
     */
    public function __construct(private readonly array $locations, private readonly ?string $cache = null)
    {
    }

    /** @param  class-string<Component>  $class */
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

        foreach ($this->locations as $location) {
            if (str_starts_with($class, trim($location['namespace'], '\\').'\\')) {
                return Naming::name($class, $location['namespace']);
            }
        }

        return Naming::name($class, substr($class, 0, (int) strrpos($class, '\\')));
    }

    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $this->discovered = true;

        if ($this->cache !== null && is_file($this->cache)) {
            $this->components += require $this->cache;

            return;
        }

        foreach ($this->locations as $location) {
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
                    $this->components[Naming::name($class, $location['namespace'])] ??= $class;
                }
            }
        }
    }

    /** The list for bootstrap/cache (written by fusion:build). */
    public function export(): string
    {
        return '<?php return '.var_export($this->all(), true).';'.PHP_EOL;
    }
}
