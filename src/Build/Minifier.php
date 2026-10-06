<?php

namespace Nitro\Fusion\Build;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Minifies the bundle's files with the application's esbuild (resources/minify.cjs, run from
 * esbuild.root so that esbuild resolves from its node_modules), as `vite build` does, keeping what
 * it minified in a cache. A part of the Bundle.
 */
final class Minifier
{
    /**
     * @param  string  $cache  the directory of minified files, by a hash of the code
     * @param  array{node?: string, root?: string}  $esbuild  the node binary, and the directory esbuild is installed in
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $cache,
        private readonly array $esbuild = [],
    ) {
    }

    /**
     * Minify every chunk (name => code) in one Node process (starting Node, ~0.2s, is most of the
     * cost of minifying one chunk), esbuild minifying them in parallel. A chunk minified before
     * (the same code, minifier and esbuild) comes from the cache, so a build where one page changed
     * minifies that page only. $minified tells how many were.
     *
     * @param  array<string, string>  $chunks
     * @return array<string, string>
     *
     * @throws RuntimeException when node or esbuild is missing, or esbuild rejects the code
     */
    public function minify(array $chunks, int &$minified = 0, bool $prune = false): array
    {
        $root = $this->esbuild['root'] ?? getcwd();
        $script = dirname(__DIR__, 2).'/resources/minify.cjs';
        $tool = hash('sha256', (string) @file_get_contents($script).(string) @file_get_contents($root.'/node_modules/esbuild/package.json'));

        $out = [];
        $keys = [];
        $missing = [];

        foreach ($chunks as $name => $code) {
            $keys[$name] = $this->cache.DIRECTORY_SEPARATOR.hash('sha256', $tool."\0".$code).'.js';

            if ($this->files->exists($keys[$name])) {
                $out[$name] = (string) $this->files->get($keys[$name]);
            } else {
                $missing[$name] = $code;
            }
        }

        $minified = count($missing);

        if ($missing !== []) {
            $process = new Process([$this->esbuild['node'] ?? 'node', $script, '--batch'], $root, null, json_encode((object) $missing, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 300);

            try {
                $process->run();
            } catch (Throwable $exception) {
                throw new RuntimeException('fusion:build minifies with esbuild, run by Node: '.$exception->getMessage(), 0, $exception);
            }

            if (! $process->isSuccessful()) {
                $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

                throw new RuntimeException('fusion:build could not minify the components with esbuild: '
                    .($error !== '' ? $error : 'node exited with '.$process->getExitCode()).'.');
            }

            $this->files->ensureDirectoryExists($this->cache);

            foreach (json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR) as $name => $code) {
                $out[$name] = $code;
                $this->files->put($keys[$name], $code);
            }
        }

        /** A build's cache keeps what it used: a chunk that changed leaves its old minified code behind. */
        if ($prune) {
            $used = array_map(fn (string $path) => basename($path), $keys);

            foreach ($this->files->glob($this->cache.DIRECTORY_SEPARATOR.'*.js') as $old) {
                if (! in_array(basename($old), $used, true)) {
                    $this->files->delete($old);
                }
            }
        }

        return array_replace($chunks, $out);
    }
}
