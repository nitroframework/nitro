<?php

namespace Nitro\Build;

use Illuminate\Filesystem\Filesystem;
use Nitro\Compiler\Components;
use Nitro\Support\Registry;
use Nitro\View\ViewEngines;
use ReflectionClass;

/**
 * The files the bundle is compiled from: the compiler, the components' classes (their parents and
 * traits too), their views (and what a Blade view includes or uses as <x-components>), and the
 * #[Browser] classes the last compile reached. A change to any is another version.
 *
 * Finding them reflects every component; nitro:build records them (sources.php, in the compiled
 * directory), with when each file and its folder last changed, and what the build worked out from
 * them alone (Chunks' map). While none has changed, and the components and #[Browser] classes are
 * the same, a request takes them from there.
 */
final class Sources
{
    /** @var array{classes: list<string>, files: array<string, int>, derived: array<string, mixed>}|false|null the record, read once; false: none holds */
    private array|false|null $record = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly Registry $registry,
        private readonly Components $components,
        private readonly ViewEngines $views,
        private readonly BrowserClassFiles $browserClasses,
        private readonly string $directory,
    ) {
    }

    /** Each file with when it last changed ("path:time", a line each; no time: it is gone), for the version. */
    public function stamp(): string
    {
        $files = $this->recorded()['files'] ?? array_combine($found = $this->find(), array_map(fn (string $file) => @filemtime($file), $found));

        return implode("\n", array_map(fn (string $file, int|false $time) => $file.':'.$time, array_keys($files), $files));
    }

    /** What the build recorded as worked out from these files ($key), while the record holds. */
    public function derived(string $key): mixed
    {
        return $this->recorded()['derived'][$key] ?? null;
    }

    /**
     * Records the files as they are now, for the requests after a build, with what was worked out
     * from them alone.
     *
     * @param  array<string, mixed>  $derived
     */
    public function record(array $derived = []): void
    {
        $files = $this->find();
        $folders = array_values(array_unique(array_map('dirname', $files)));
        $times = fn (array $paths) => array_combine($paths, array_map(fn (string $path) => (int) filemtime($path), $paths));

        $this->files->ensureDirectoryExists($this->directory);
        $this->files->put($this->path(), '<?php return '.var_export([
            'components' => $this->registry->all(),
            'classes' => $this->browserClasses->all(),
            'files' => $times($files),
            'folders' => $times($folders),
            'derived' => $derived,
        ], true).';'.PHP_EOL);
        $this->record = null;
    }

    /** Forgets the record (nitro:clear). */
    public function clear(): void
    {
        $this->files->delete($this->path());
        $this->record = null;
    }

    /**
     * The record, while it still holds: a file or folder changed since it (an edit, a file added
     * or removed), or another set of components or #[Browser] classes, and it is not used.
     *
     * @return array{classes: list<string>, files: array<string, int>, derived: array<string, mixed>}|null
     */
    private function recorded(): ?array
    {
        $record = ($this->record ??= $this->read() ?? false) ?: null;

        /** A compile since may have reached other #[Browser] classes. */
        return $record !== null && $record['classes'] === $this->browserClasses->all() ? $record : null;
    }

    /** @return array{classes: list<string>, files: array<string, int>, derived: array<string, mixed>}|null */
    private function read(): ?array
    {
        if (! is_file($this->path())) {
            return null;
        }

        $recorded = require $this->path();

        if (! is_array($recorded) || ! isset($recorded['components'], $recorded['classes'], $recorded['files'], $recorded['folders'], $recorded['derived'])
            || $recorded['components'] !== $this->registry->all()) {
            return null;
        }

        foreach ([...$recorded['files'], ...$recorded['folders']] as $path => $time) {
            if (@filemtime($path) !== $time) {
                return null;
            }
        }

        return ['classes' => $recorded['classes'], 'files' => $recorded['files'], 'derived' => $recorded['derived']];
    }

    /** @return list<string> */
    private function find(): array
    {
        $files = array_map(fn ($file) => $file->getPathname(), $this->files->allFiles(dirname(__DIR__).DIRECTORY_SEPARATOR.'Compiler'));
        $files = [...$files, ...$this->browserClasses->all()];

        foreach ($this->registry->all() as $name => $class) {
            for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                $files = [...$files, ...$this->classFiles($reflection)];
            }

            $meta = $this->components->meta($name);

            /** A store has no view. */
            if (! $meta->store) {
                $files = [...$files, ...$this->views->of($meta)->files($meta)];
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * A class's file and those of the traits it uses (their methods are compiled with it).
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    private function classFiles(ReflectionClass $class): array
    {
        $files = $class->getFileName() ? [$class->getFileName()] : [];

        foreach ($class->getTraits() as $trait) {
            $files = [...$files, ...$this->classFiles($trait)];
        }

        return $files;
    }

    private function path(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'sources.php';
    }
}
