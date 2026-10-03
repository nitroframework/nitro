<?php

namespace Nitro\Fusion\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Support\Naming;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * `php artisan fusion:build`: the production build. Every component compiled for the browser and
 * minified with esbuild (as `vite build` does), published under public/ (fusion.static), and the
 * component list cached. A component that cannot run in the browser fails the build, naming the
 * file and line.
 *
 * It says how it goes, a line a step with its time: compiling (a progress bar with the component
 * being compiled; -v lists them), minifying (in one Node process; chunks unchanged since the last
 * build come from its cache), publishing.
 */
#[AsCommand(name: 'fusion:build')]
class BuildCommand extends Command
{
    protected $signature = 'fusion:build';

    protected $description = 'Build the Fusion components for the browser (minified with esbuild)';

    private ?ProgressBar $bar = null;

    /** What the step being done is, for its line once it is done. */
    private string $step = '';

    /** The component being compiled, named when the build fails on it. */
    private ?string $component = null;

    public function handle(Fusion $fusion, Bundle $bundle, Filesystem $files): int
    {
        $manifest = $this->laravel->bootstrapPath('cache/fusion.php');
        $files->delete($manifest);
        $this->newLine();

        try {
            $path = $bundle->build(static: true, report: $this->report(...));
        } catch (RuntimeException $e) {
            $this->bar?->clear();
            $this->newLine();
            $this->components->error(($this->component !== null && $this->step === 'compiling' ? "Compiling {$this->component}: " : '').$e->getMessage());

            return self::FAILURE;
        }

        $files->put($manifest, $fusion->registry()->export());

        $names = array_keys($fusion->registry()->all());
        $pages = count(array_filter($names, Naming::isPage(...)));

        $this->newLine();
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

    /** The build's steps (Bundle::build()'s report), as lines. */
    private function report(string $event, mixed ...$details): void
    {
        switch ($event) {
            case 'compiling':
                $this->step = 'compiling';
                $this->label = "Compiling {$details[0]} components";

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
                $this->label = "Minifying {$details[0]} chunks with esbuild";
                break;
            case 'minified':
                $this->done($this->label.($details[1] === 0 ? ' (all unchanged)' : " ({$details[1]} changed)"), $details[0]);
                break;
            case 'publishing':
                $this->step = 'publishing';
                $this->label = 'Publishing to public/'.basename((string) $details[0]);
                break;
            case 'published':
                $this->done($this->label, $details[0]);
                break;
        }
    }

    private string $label = '';

    private function done(string $label, float $seconds): void
    {
        $time = $seconds >= 1 ? number_format($seconds, 1).'s' : number_format($seconds * 1000).'ms';

        $this->components->twoColumnDetail($label, "<fg=gray>{$time}</> <fg=green;options=bold>DONE</>");
    }
}
