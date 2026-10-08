<?php

namespace Nitro\Fusion\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Support\Registry;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'fusion:clear')]
class ClearCommand extends Command
{
    protected $signature = 'fusion:clear';

    protected $description = 'Remove the compiled Fusion components';

    public function handle(Bundle $bundle, Filesystem $files): int
    {
        $bundle->clear();
        $files->delete($this->laravel->bootstrapPath(Registry::CACHE));
        /** Compiled Blade views call Fusion's parts (a <fusion:> tag, @fusion, @portal): compiled again with this version's. */
        $this->callSilent('view:clear');

        $this->components->info('Compiled Fusion components cleared.');

        return self::SUCCESS;
    }
}
