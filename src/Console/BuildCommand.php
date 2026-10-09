<?php

namespace Nitro\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nitro\Build\Bundle;
use Nitro\Compiler\Components;
use Nitro\Support\Naming;
use Nitro\Support\Protocol;
use Nitro\Support\Registry;
use Nitro\View\ScriptEngine;
use Nitro\View\ViewEngines;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * `php artisan nitro:build`: the production build. Every component compiled for the browser and
 * minified with esbuild (as `vite build` does), published under public/ (nitro.static), and the
 * component list cached. A component that cannot run in the browser fails the build, naming the
 * file and line.
 *
 * It says how it goes, a line a step with its time: compiling (a progress bar with the component
 * being compiled; -v lists them), minifying (in one Node process; files unchanged since the last
 * build come from its cache), copying to public/. Then what was built: each file and its size
 * (-v: every on-demand file and what is in it), what a first visit downloads, the version.
 */
#[AsCommand(name: 'nitro:build')]
class BuildCommand extends Command
{
    protected $signature = 'nitro:build';

    protected $description = 'Build the Nitro components for the browser (minified with esbuild)';

    private ?ProgressBar $bar = null;

    /** What the step being done is, for its line once it is done. */
    private string $step = '';

    /** The component being compiled, named when the build fails on it. */
    private ?string $component = null;

    public function handle(Bundle $bundle, Filesystem $files): int
    {
        $manifest = $this->laravel->bootstrapPath(Registry::CACHE);
        $files->delete($manifest);
        $this->newLine();

        try {
            $bundle->build(static: true, report: $this->report(...));
        } catch (RuntimeException $exception) {
            $this->bar?->clear();
            $this->newLine();
            $this->components->error(($this->component !== null && $this->step === 'compiling' ? "Compiling {$this->component}: " : '').$exception->getMessage());

            return self::FAILURE;
        }

        $files->put($manifest, $this->laravel->make(Registry::class)->export());
        $this->summary($this->built);

        return self::SUCCESS;
    }

    /**
     * What was built: each file and its size, what a first visit downloads, the version, and
     * where the files are served from.
     *
     * @param  array{version: string, obfuscated: bool, directory: ?string, runtime: array{name: string, bytes: int}, files: list<array{group: string, name: string, bytes: int, members: list<string>}>}  $built
     */
    private function summary(array $built): void
    {
        $main = $built['files'][0];
        $onDemand = array_slice($built['files'], 1);
        $sizes = array_column($onDemand, 'bytes');
        $total = $built['runtime']['bytes'] + array_sum(array_column($built['files'], 'bytes'));

        $rows = [
            ['Runtime', $built['runtime']['name'], $built['runtime']['bytes'], ''],
            ['Main bundle', $main['name'], $main['bytes'], 'components and shared code'],
        ];

        if ($onDemand !== []) {
            $rows[] = ['On demand', count($onDemand).' files, each loaded when first needed', array_sum($sizes), 'largest '.$this->size(max($sizes))];

            if ($this->output->isVerbose()) {
                foreach ($onDemand as $file) {
                    $rows[] = ['', $file['name'], $file['bytes'], implode(', ', $file['members'])];
                }
            }
        }

        $rows[] = ['Total', '', $total, ''];

        $this->newLine();

        foreach ($rows as [$label, $file, $bytes, $note]) {
            $this->components->twoColumnDetail(
                trim(($label !== '' ? "<fg=green;options=bold>{$label}</> " : '  ').($file !== '' ? "<fg=cyan>{$file}</>" : '').($note !== '' ? " <fg=gray>{$note}</>" : '')),
                '<fg=yellow;options=bold>'.$this->size($bytes).'</>',
            );
        }

        $first = $built['runtime']['bytes'] + $main['bytes'];
        $this->newLine();
        $this->line('  '.($onDemand === []
            ? 'A first visit downloads the runtime and the main bundle: <fg=yellow;options=bold>'.$this->size($first).'</>.'
            : 'A first visit downloads the runtime, the main bundle and that page\'s file: <fg=yellow;options=bold>about '
                .round(($first + min($sizes)) / 1024).'–'.round(($first + max($sizes)) / 1024).' KB</>.'));
        $this->line('  Version <fg=cyan>'.$built['version'].'</> · '.($built['obfuscated'] ? '<fg=green>obfuscated</>' : '<fg=yellow>not obfuscated</>'));
        $this->newLine();

        if ($built['directory'] === null) {
            $this->components->info('Done. The scripts are in '.$this->laravel->make('config')->get('nitro.compiled').', served by Nitro\'s routes.');

            return;
        }

        $this->components->info('Done. The scripts are now plain files in <fg=cyan>public/'.basename($built['directory']).'</>.');
        $this->line('  The web server sends them directly, without PHP, and browsers can cache them,');
        $this->line('  since every build gets new file names. @nitroScripts in your layout already points at them.');
        $this->newLine();
    }

    private function size(int $bytes): string
    {
        return number_format($bytes / 1024, 1).' KB';
    }

    /**
     * What their views are written in, when not all Blade: " (6 Blade, 2 Vue, 2 React)".
     *
     * @param  list<string>  $names
     */
    private function engines(array $names): string
    {
        $engines = [];

        foreach ($names as $name) {
            $engine = $this->laravel->make(Components::class)->meta($name)->engine;
            $engines[$engine] = ($engines[$engine] ?? 0) + 1;
        }

        if (array_keys($engines) === [] || array_keys($engines) === [Protocol::bladeEngine()]) {
            return '';
        }

        /** Blade first, then the engines as nitro.views lists them; each by its label. */
        $registry = $this->laravel->make(ViewEngines::class);
        $order = [Protocol::bladeEngine(), ...array_map(fn (ScriptEngine $engine) => $engine->name(), $registry->scripts())];
        $position = fn (string $engine) => array_search($engine, $order, true) === false ? PHP_INT_MAX : (int) array_search($engine, $order, true);
        uksort($engines, fn (string $left, string $right) => $position($left) <=> $position($right));
        $label = fn (string $engine) => $registry->named($engine)?->label() ?? ucfirst($engine);

        return ' ('.implode(', ', array_map(fn (string $engine, int $count) => "{$count} {$label($engine)}", array_keys($engines), $engines)).')';
    }

    /** The build's steps (Bundle::build()'s report), as lines. */
    private function report(string $event, mixed ...$details): void
    {
        switch ($event) {
            case 'compiling':
                $this->step = 'compiling';
                $names = array_keys($this->laravel->make(Registry::class)->all());
                $pages = array_values(array_filter($names, Naming::isPage(...)));
                $stores = count(array_filter($names, Naming::isStore(...)));
                $components = array_values(array_diff($names, $pages, array_filter($names, Naming::isStore(...))));
                $this->label = sprintf(
                    'Compiling %d components%s%s and %d pages%s to JavaScript',
                    count($components),
                    $this->engines($components),
                    $stores === 0 ? '' : sprintf(', %d %s', $stores, $stores === 1 ? 'store' : 'stores'),
                    count($pages),
                    $this->engines($pages),
                );

                if (! $this->output->isVerbose() && $this->output->isDecorated()) {
                    $this->bar = $this->output->createProgressBar($details[0]);
                    $this->bar->setFormat('  %current%/%max% [%bar%] %message%');
                    $this->bar->setMessage('');
                    $this->bar->start();
                }

                break;
            case 'component':
                $this->component = $details[0];
                $this->bar?->setMessage($details[0]);
                $this->bar?->advance();

                if ($this->output->isVerbose()) {
                    $this->line("  <fg=gray>{$details[0]}</>");
                }

                break;
            case 'compiled':
                $this->bar?->clear();
                $this->bar = null;
                $this->done($this->label, $details[0]);
                break;
            case 'minifying':
                $this->step = 'minifying';
                $this->files = $details[0];
                $this->label = 'Minifying the main bundle'.($details[0] > 1 ? ' and '.($details[0] - 1).' on-demand files' : '').' with esbuild';
                break;
            case 'minified':
                $cached = $this->files - $details[1];
                $this->done($this->label.' ('.match (true) {
                    $details[1] === 0 => $this->files === 1 ? 'cached' : "all {$this->files} cached",
                    $cached === 0 => "{$details[1]} minified",
                    default => "{$details[1]} minified, {$cached} cached",
                }.')', $details[0]);
                break;
            case 'publishing':
                $this->step = 'publishing';
                $this->label = "Copying {$details[1]} files to public/".basename((string) $details[0]);
                break;
            case 'published':
                $this->done($this->label, $details[0]);
                break;
            case 'built':
                $this->built = $details[0];
                break;
        }
    }

    /** How many files the build has (the main bundle and those loaded on demand). */
    private int $files = 0;

    /** @var array<string, mixed> what Bundle::build() made (its 'built' report) */
    private array $built = [];

    private string $label = '';

    private function done(string $label, float $seconds): void
    {
        $time = $seconds >= 1 ? number_format($seconds, 1).'s' : number_format($seconds * 1000).'ms';

        $this->components->twoColumnDetail($label, "<fg=gray>{$time}</> <fg=green;options=bold>DONE</>");
    }
}
