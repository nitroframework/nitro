<?php

namespace Nitro\Fusion\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Support\Naming;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan fusion:build`: the production build. Every component compiled for the browser and
 * minified with esbuild (as `vite build` does), published under public/ (fusion.static), and the
 * component list cached. A component that cannot run in the browser fails the build, naming the
 * file and line.
 */
#[AsCommand(name: 'fusion:build')]
class BuildCommand extends Command
{
    protected $signature = 'fusion:build';

    protected $description = 'Build the Fusion components for the browser (minified with esbuild)';

    public function handle(Fusion $fusion, Bundle $bundle, Filesystem $files): int
    {
        $manifest = $this->laravel->bootstrapPath('cache/fusion.php');
        $files->delete($manifest);

        try {
            $path = $bundle->build(static: true);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $files->put($manifest, $fusion->registry()->export());

        $names = array_keys($fusion->registry()->all());
        $pages = count(array_filter($names, Naming::isPage(...)));

        $this->components->info(sprintf(
            '%d Fusion components and %d pages built: %s (%s KB).',
            count($names) - $pages,
            $pages,
            basename($path),
            number_format($files->size($path) / 1024, 1),
        ));

        if ($bundle->staticFile() !== null) {
            $this->components->info('Published to public/'.dirname((string) $bundle->staticFile()).': served without PHP.');
        }

        return self::SUCCESS;
    }
}
