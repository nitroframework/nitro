<?php

namespace Nitro\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LogicException;
use Nitro\Support\Naming;
use Nitro\Support\Registry;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * php artisan make:nitro Counter (a component), Posts/Index --page, Cart --store: the class, and
 * for a component or a page its Blade view, where nitro.components, nitro.pages and nitro.stores
 * say (issues::CommentBox: where nitro.namespaces.issues says). Written from resources/stubs, or
 * the app's own stubs/nitro.*.stub; an existing file is left as it is without --force.
 */
#[AsCommand(name: 'make:nitro')]
class MakeCommand extends Command
{
    protected $signature = 'make:nitro
        {name : The class, such as Counter, Posts/Index or issues::CommentBox}
        {--page : A page: routed, with a layout and a title}
        {--store : A store: state several components share, with no view}
        {--force : Write over files that are there}';

    protected $description = 'Create a Nitro component, page or store';

    public function handle(Filesystem $files, Registry $registry): int
    {
        if ($this->option('page') && $this->option('store')) {
            $this->components->error('A class is a page or a store, not both.');

            return self::FAILURE;
        }

        $kind = $this->option('page') ? 'page' : ($this->option('store') ? 'store' : 'component');
        [$in, $local] = Naming::split((string) $this->argument('name'));
        $segments = $this->segments($local);

        if ($segments === []) {
            $this->components->error('Name the class with letters, digits and folders: Counter, Posts/Index, issues::CommentBox.');

            return self::FAILURE;
        }

        if ($in !== null && ! $registry->hasNamespace($in)) {
            $this->components->error("There is no namespace [{$in}] in nitro.namespaces.");

            return self::FAILURE;
        }

        try {
            [$fullClass, $classFile] = $registry->placeOf($kind, $segments, $in);
        } catch (LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
        $class = Str::afterLast($fullClass, '\\');
        $namespace = Str::beforeLast($fullClass, '\\');
        $name = $registry->name($fullClass);

        $written = [$classFile => $this->stub($files, $kind, [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $class,
            '{{ title }}' => Str::headline(implode(' ', $segments)),
        ])];

        if ($kind !== 'store') {
            /** Where Laravel looks for views first: config/view.php's paths, or the Blade namespace's (loadViewsFrom). */
            [$bladeNamespace, $view] = Naming::split($registry->view($name));
            $finder = $this->laravel->make('view')->getFinder();
            $roots = $bladeNamespace === null ? $finder->getPaths() : ($finder->getHints()[$bladeNamespace] ?? []);

            if ($roots === []) {
                $this->components->error("Blade has no views named [{$bladeNamespace}::]: load them in the provider (\$this->loadViewsFrom(..., '{$bladeNamespace}')).");

                return self::FAILURE;
            }

            $viewsRoot = rtrim((string) $roots[0], '/\\');
            $written[$viewsRoot.DIRECTORY_SEPARATOR.str_replace('.', DIRECTORY_SEPARATOR, $view).'.blade.php'] = $this->stub($files, 'view', []);
        }

        /** Its folders, for a page's URL. */
        array_pop($segments);

        foreach (array_keys($written) as $file) {
            if ($files->exists($file) && ! $this->option('force')) {
                $this->components->error("[{$this->relative($file)}] is there already: --force writes over it.");

                return self::FAILURE;
            }
        }

        foreach ($written as $file => $contents) {
            $files->ensureDirectoryExists(dirname($file));
            $files->put($file, $contents);
            $this->components->info("Created [{$this->relative($file)}].");
        }

        $this->line(match ($kind) {
            'component' => "  Place it in a view: <nitro:{$name} />",
            'page' => "  Route it: Route::nitro('{$this->url($segments, $class)}', \\{$namespace}\\{$class}::class);",
            'store' => "  Use it from a component: public \\{$namespace}\\{$class} \$".Str::camel($class).';',
        });

        return self::SUCCESS;
    }

    /**
     * The class's folders and name, StudlyCase: Posts/Index, posts.index, Posts\Index, post-card.
     *
     * @return list<string> empty when the name is not one
     */
    private function segments(string $name): array
    {
        $segments = array_values(array_filter(preg_split('#[/\\\\.]+#', trim($name)) ?: [], fn (string $s) => $s !== ''));

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $segment) !== 1) {
                return [];
            }
        }

        return array_map(fn (string $segment) => Str::studly($segment), $segments);
    }

    /**
     * A template, from the app's stubs/ when it has its own, with its {{ placeholders }} filled.
     *
     * @param  array<string, string>  $values  each {{ placeholder }} and what it becomes
     */
    private function stub(Filesystem $files, string $kind, array $values): string
    {
        $own = $this->laravel->basePath("stubs/nitro.{$kind}.stub");
        $stub = $files->get($files->exists($own) ? $own : dirname(__DIR__, 2)."/resources/stubs/nitro.{$kind}.stub");

        return str_replace(array_keys($values), array_values($values), $stub);
    }

    /**
     * A URL for a page: its folders and name in kebab case, an Index the folder's own.
     *
     * @param  list<string>  $segments
     */
    private function url(array $segments, string $class): string
    {
        $parts = array_map(fn (string $s) => Str::kebab($s), $class === 'Index' ? $segments : [...$segments, $class]);

        return '/'.implode('/', $parts);
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace([$this->laravel->basePath(), '\\'], ['', '/'], $file), '/');
    }
}
