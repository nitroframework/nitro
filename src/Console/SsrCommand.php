<?php

namespace Nitro\Fusion\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runs the render service for server rendering (fusion.ssr): the app's SSR bundle, which
 * `vite build --ssr` made from its entry (nitro-fusion/ssr). It listens on fusion.ssr.url's port
 * and runs until stopped; in production, keep it running as a service (Supervisor, systemd).
 */
#[AsCommand(name: 'fusion:ssr')]
class SsrCommand extends Command
{
    protected $signature = 'fusion:ssr {--bundle= : The SSR bundle, if not fusion.ssr.bundle}';

    protected $description = 'Start the render service for Vue, React and Solid views';

    public function handle(): int
    {
        $config = (array) $this->laravel->make('config')->get('fusion.ssr', []);
        $bundle = (string) ($this->option('bundle') ?: ($config['bundle'] ?? $this->laravel->basePath('bootstrap/ssr/ssr.js')));
        $port = parse_url((string) ($config['url'] ?? 'http://127.0.0.1:13714'), PHP_URL_PORT) ?: 13714;

        if (! is_file($bundle)) {
            $this->components->error("No SSR bundle at {$bundle}. Build it with: npx vite build --ssr resources/js/ssr.js");

            return self::FAILURE;
        }

        if (! ($config['enabled'] ?? false)) {
            $this->components->warn('fusion.ssr.enabled is off (FUSION_SSR): pages will not ask the render service.');
        }

        $node = (string) $this->laravel->make('config')->get('fusion.esbuild.node', 'node');
        $process = proc_open([$node, $bundle], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $this->laravel->basePath(), [...getenv(), 'FUSION_SSR_PORT' => (string) $port]);

        if (! is_resource($process)) {
            $this->components->error("Could not start {$node}.");

            return self::FAILURE;
        }

        return proc_close($process) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
