<?php

namespace Nitro\Fusion\Build;

use Illuminate\Filesystem\Filesystem;
use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Support\Registry;
use Nitro\Fusion\View\ViewEngines;
use ReflectionClass;

/**
 * The files the bundle is compiled from: the compiler, the components' classes (their parents and
 * traits too), their views (and what a Blade view includes or uses as <x-components>), and the
 * #[Browser] classes the last compile reached. A change to any is another version.
 */
final class Sources
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly Registry $registry,
        private readonly Components $components,
        private readonly ViewEngines $views,
        private readonly BrowserClassFiles $browserClasses,
    ) {
    }

    /** @return list<string> */
    public function all(): array
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
}
