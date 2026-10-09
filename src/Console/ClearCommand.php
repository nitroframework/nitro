<?php

namespace Nitro\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nitro\Build\Bundle;
use Nitro\Support\Registry;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'nitro:clear')]
class ClearCommand extends Command
{
    protected $signature = 'nitro:clear';

    protected $description = 'Remove the compiled Nitro components';

    public function handle(Bundle $bundle, Filesystem $files): int
    {
        $bundle->clear();
        $files->delete($this->laravel->bootstrapPath(Registry::CACHE));
        /** Compiled Blade views call Nitro's parts (a <nitro:> tag, @nitro, @portal): compiled again with this version's. */
        $this->callSilent('view:clear');

        $this->components->info('Compiled Nitro components cleared.');

        return self::SUCCESS;
    }
}
