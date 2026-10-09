<?php

namespace Nitro\Build;

use Illuminate\Filesystem\Filesystem;

/**
 * The compiled directory (nitro.compiled): a version's bundle and chunks, readable or built,
 * and what their code uses. Each version's files replace the last version's.
 *
 *   app-<version>.js                   the main bundle, compiled on first use
 *   app-<version>.min.js               nitro:build's
 *   app-<version>.<group>.chunk.js     a group's chunk (.chunk.min.js)
 *   app-<version>.uses.json            what the version's code uses (Compiler\Uses), its chunks, its broken components
 */
final class CompiledFiles
{
    public function __construct(private readonly Filesystem $files, private readonly string $directory)
    {
    }

    public function path(string $version, string $group = '', bool $built = false): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'app-'.$version.($group === '' ? '' : '.'.$group.'.chunk').($built ? '.min' : '').'.js';
    }

    public function usesPath(string $version): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'app-'.$version.'.uses.json';
    }

    /**
     * What the version's code uses, as written; null when it is not there.
     *
     * @return array<string, mixed>|null
     */
    public function uses(string $version): ?array
    {
        if (! $this->files->exists($this->usesPath($version))) {
            return null;
        }

        $uses = json_decode((string) $this->files->get($this->usesPath($version)), true);

        return is_array($uses) ? $uses : null;
    }

    public function exists(string $path): bool
    {
        return $this->files->exists($path);
    }

    /**
     * Write a version's files, and remove the files of other versions (nothing asks for them any more).
     *
     * @param  array<string, string>  $files  path => contents
     */
    public function write(array $files): void
    {
        foreach ($files as $path => $contents) {
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path.'.tmp', $contents);
            $this->files->move($path.'.tmp', $path);
        }

        $keep = array_map(fn (string $path) => realpath($path), array_keys($files));

        foreach ($this->files->glob($this->directory.DIRECTORY_SEPARATOR.'app-*') as $old) {
            if (! in_array(realpath($old), $keep, true)) {
                $this->files->delete($old);
            }
        }
    }

    /** Remove every version's files (Nitro's only: the directory's own, such as a .gitignore, stay). */
    public function clear(): void
    {
        foreach ($this->files->glob($this->directory.DIRECTORY_SEPARATOR.'app-*') as $file) {
            $this->files->delete($file);
        }
    }
}
