<?php

namespace Nitro\Fusion\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Support\Naming;
use Nitro\Fusion\ViewPages;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan fusion:page-class employees.index`: a view page (@page) grown into a page class.
 * The class (App\Fusion\Pages\Employees\Index) gets the view's props and state as typed
 * properties, and the view loses its @page (the class says it now). The controller is unchanged:
 * it still renders the page by its name and takes its actions; the class is where the page's
 * browser methods, #[Computed], updated hooks, mounted() and uploads go.
 */
#[AsCommand(name: 'fusion:page-class')]
class PageClassCommand extends Command
{
    protected $signature = 'fusion:page-class {page : The view page, as Fusion::render() names it (employees.index)}';

    protected $description = 'Make a page class for a view page (@page), for its browser methods, hooks and uploads';

    public function handle(Filesystem $files): int
    {
        $name = Naming::PAGES.Naming::bare((string) $this->argument('page'));
        $config = (array) $this->laravel->make('config')->get('fusion.pages');

        try {
            $view = $this->laravel->make('view')->getFinder()->find(Naming::view($name, (string) ($config['views'] ?? 'fusion.pages')));
        } catch (\InvalidArgumentException) {
            $this->components->error("There is no view for [{$name}].");

            return self::FAILURE;
        }

        $relative = implode('\\', array_map(fn (string $segment) => \Illuminate\Support\Str::studly($segment), explode('.', Naming::bare($name))));
        $class = trim((string) $config['namespace'], '\\').'\\'.$relative;
        $path = rtrim((string) $config['path'], '\\/').DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';

        if ($files->exists($path)) {
            $this->components->error("{$class} exists already ({$path}).");

            return self::FAILURE;
        }

        try {
            $source = (string) $files->get($view);
            $php = ViewPages::pageClass($source, substr($class, 0, (int) strrpos($class, '\\')), substr($class, (int) strrpos($class, '\\') + 1));
        } catch (CompileException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $php);
        $files->put($view, ViewPages::withoutDeclaration($source));

        $this->components->info("{$class} made, and @page taken out of the view: the class says it now.");
        $this->components->bulletList([
            'Its controller is unchanged: it renders the page by its name and takes its actions.',
            'Add browser methods, #[Computed], updated hooks, mounted() or uploads to the class.',
        ]);

        return self::SUCCESS;
    }
}
