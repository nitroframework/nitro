<?php

namespace Nitro\Fusion\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Fusion;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan fusion:build`: the production build. Every component compiled for the browser and
 * minified with esbuild (as `vite build` does), and the component list cached. A component that
 * cannot run in the browser fails the build, naming the file and line.
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
            $path = $bundle->build();
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $files->put($manifest, $fusion->registry()->export());

        $this->components->info(sprintf(
            '%d Fusion components built: %s (%s KB).',
            count($fusion->registry()->all()),
            basename($path),
            number_format($files->size($path) / 1024, 1),
        ));

        return self::SUCCESS;
    }
}
