<?php

namespace Nitro\Fusion\Build;

use Illuminate\Filesystem\Filesystem;
use Nitro\Fusion\Compiler\BrowserClasses;

/**
 * The files of the #[Browser] classes the last compile reached, kept in classes.json (not an
 * app-* file: writing a version's files would remove it). Which classes a bundle reaches is known
 * only once it is compiled, so the version counts the classes recorded then: editing one makes a
 * new version. A part of the Bundle.
 */
final class BrowserClassFiles
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> */
    public function all(): array
    {
        if (! $this->files->exists($this->path())) {
            return [];
        }

        $files = json_decode((string) $this->files->get($this->path()), true);

        return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
    }

    /**
     * Records the files of the #[Browser] classes a compile reached. True when they are not the
     * ones recorded (a component reached a new class): the version is then worked out again.
     *
     * @param  list<string>  $keys  BrowserClasses' keys ("Class::method", "Enum::")
     */
    public function record(array $keys): bool
    {
        $files = [];

        foreach ($keys as $key) {
            $class = BrowserClasses::split($key)[0];

            /** "*::label" names no class: the enums it was compiled for are listed themselves. */
            if ($class !== '*') {
                $files = [...$files, ...BrowserClasses::files($class)];
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        if ($files === $this->all()) {
            return false;
        }

        $this->files->ensureDirectoryExists($this->directory);
        $this->files->put($this->path(), json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return true;
    }

    /** Forgets them (fusion:clear). */
    public function clear(): void
    {
        $this->files->delete($this->path());
    }

    private function path(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'classes.json';
    }
}
