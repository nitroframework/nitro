<?php

namespace Nitro\Fusion\Build;

use Illuminate\Filesystem\Filesystem;

/**
 * fusion:build's static copy of the build (fusion.static, public/fusion), as `vite build` writes
 * public/build: the web server (or a CDN) serves it without PHP. Only files named as Fusion names
 * them are Fusion's, so the directory may hold others.
 */
final class StaticFiles
{
    public function __construct(
        private readonly Filesystem $files,
        /** The directory (public/fusion); null: no static files */
        private readonly ?string $directory = null,
        /** Its path under public/, for asset() */
        private readonly string $path = 'fusion',
    ) {
    }

    public function directory(): ?string
    {
        return $this->directory;
    }

    /** A published file's path under public/ (fusion/<name>), null when it is not there. */
    public function url(string $name): ?string
    {
        if ($this->directory === null) {
            return null;
        }

        return $this->files->exists($this->directory.DIRECTORY_SEPARATOR.$name) ? $this->path.'/'.$name : null;
    }

    /** The path under public/ of a name not yet known ({id} in a chunk's): fusion/<name>. */
    public function pattern(string $name): string
    {
        return $this->path.'/'.$name;
    }

    /**
     * Write the files (name => contents), and remove the ones of other builds.
     *
     * @param  array<string, string>  $files
     */
    public function publish(array $files): void
    {
        if ($this->directory === null) {
            return;
        }

        foreach ($files as $name => $contents) {
            $path = $this->directory.DIRECTORY_SEPARATOR.$name;
            $this->files->ensureDirectoryExists($this->directory);
            $this->files->put($path.'.tmp', $contents);
            $this->files->move($path.'.tmp', $path);
        }

        foreach ($this->files->glob($this->directory.DIRECTORY_SEPARATOR.'*.js') as $old) {
            $name = basename($old);

            if (! isset($files[$name]) && preg_match('/^(app|fusion|[0-9a-f]{8})-[0-9a-f]{12,16}\.js$/', $name) === 1) {
                $this->files->delete($old);
            }
        }
    }
}
