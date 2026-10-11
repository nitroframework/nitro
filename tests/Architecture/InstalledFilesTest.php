<?php

namespace Nitro\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * What an app imports from Nitro's JavaScript (js/vite.js and js/adapters: Vite builds them in
 * the app) reaches only files a Composer install has: none left out by .gitattributes'
 * export-ignore. The tests run from the repository, where every file is there, so a file left out
 * would break only in an app.
 */
final class InstalledFilesTest extends TestCase
{
    public function test_what_the_apps_build_imports_is_installed(): void
    {
        $root = Scanner::root();
        $ignored = $this->exportIgnored($root);
        $missing = [];
        $queue = [$root.'/js/vite.js', ...$this->files($root.'/js/adapters')];
        $seen = [];

        while ($queue !== []) {
            $file = str_replace('\\', '/', (string) realpath(array_pop($queue)));

            if (isset($seen[$file])) {
                continue;
            }

            $seen[$file] = true;
            /** Its code without comments, which may show imports as examples. */
            $code = Scanner::code()[substr($file, strlen($root) + 1)] ?? (string) file_get_contents($file);
            preg_match_all('/(?:import|export)\s[^;]*?from\s+[\'"](\.{1,2}\/[^\'"]+)[\'"]|import\(\s*[\'"](\.{1,2}\/[^\'"]+)[\'"]\s*\)/', $code, $imports);

            foreach (array_filter([...$imports[1], ...$imports[2]]) as $import) {
                $target = realpath(dirname($file).'/'.$import);
                $relative = $target === false ? null : substr(str_replace('\\', '/', $target), strlen($root) + 1);

                if ($relative === null || $this->isIgnored($relative, $ignored)) {
                    $missing[] = substr($file, strlen($root) + 1)." imports {$import}";

                    continue;
                }

                if (str_ends_with($relative, '.js')) {
                    $queue[] = $target;
                }
            }
        }

        $this->assertSame([], $missing, 'Left out of a Composer install (.gitattributes export-ignore), or not there at all');
    }

    /** @return list<string> the paths .gitattributes leaves out of installs, without their leading slash */
    private function exportIgnored(string $root): array
    {
        preg_match_all('/^\/?(\S+)\s+export-ignore\s*$/m', (string) file_get_contents($root.'/.gitattributes'), $paths);

        return $paths[1];
    }

    /** @param  list<string>  $ignored */
    private function isIgnored(string $relative, array $ignored): bool
    {
        foreach ($ignored as $path) {
            if ($relative === $path || str_starts_with($relative, rtrim($path, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function files(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match('/\.(js|mjs)$/', $file->getFilename()) === 1) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
