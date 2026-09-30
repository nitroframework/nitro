<?php

namespace Nitro\Fusion\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nitro\Fusion\Build\Bundle;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'fusion:clear')]
class ClearCommand extends Command
{
    protected $signature = 'fusion:clear';

    protected $description = 'Remove the compiled Fusion components';

    public function handle(Bundle $bundle, Filesystem $files): int
    {
        $bundle->clear();
        $files->delete($this->laravel->bootstrapPath('cache/fusion.php'));

        $this->components->info('Compiled Fusion components cleared.');

        return self::SUCCESS;
    }
}
