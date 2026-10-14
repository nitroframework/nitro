<?php

namespace Nitro\Http;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Http\Request;
use Nitro\Support\Config;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: Laravel's route and config caches (`php artisan optimize`) older than what they
 * cache. A route file, a config file or .env changed since is not in them: a page routed since
 * is a 404, a config value changed since is the old one, and nothing says why.
 *
 * In development (Config::debug()) it is an exception naming the file and the fix. In production
 * the app goes on as cached: one warning in the log for each stale state, never an error page
 * for a visitor. (Nitro's own registry cache needs nothing: it finds its components again when
 * their folders change, Support\Registry.)
 */
final class StaleCaches
{
    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $stale = $this->stale();

        if ($stale !== null) {
            $message = "Laravel's {$stale['cache']} cache ({$this->relative($stale['path'])}) is older than {$this->relative($stale['changed'])}: what changed there since is not in it. Run `php artisan optimize:clear`, or `php artisan optimize` to cache it again.";

            if ($this->config->debug()) {
                throw new RuntimeException($message);
            }

            $this->warnOnce($stale, $message);
        }

        return $next($request);
    }

    /**
     * The first cache older than a file it caches: the route cache and the route files, the
     * config cache and the config files and .env.
     *
     * @return array{cache: string, path: string, changed: string}|null
     */
    private function stale(): ?array
    {
        $app = $this->app;
        $env = $app instanceof \Illuminate\Foundation\Application ? $app->environmentFilePath() : $app->basePath('.env');
        $caches = [
            'route' => [$app instanceof CachesRoutes && $app->routesAreCached() ? $app->getCachedRoutesPath() : null, [$app->basePath('routes')]],
            'config' => [$app instanceof CachesConfiguration && $app->configurationIsCached() ? $app->getCachedConfigPath() : null, [$app->configPath(), $env]],
        ];

        foreach ($caches as $cache => [$path, $sources]) {
            $cachedAt = $path === null ? false : @filemtime($path);

            if ($cachedAt === false) {
                continue;
            }

            foreach ($sources as $source) {
                foreach ($this->files($source) as $file) {
                    if ((int) @filemtime($file) > $cachedAt) {
                        return ['cache' => $cache, 'path' => $path, 'changed' => $file];
                    }
                }
            }
        }

        return null;
    }

    /** @return iterable<string> the file, or the PHP files under the folder */
    private function files(string $path): iterable
    {
        if (is_file($path)) {
            yield $path;

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    /**
     * The warning, once for this cache and this change (a marker in Nitro's compiled folder):
     * not on every request.
     *
     * @param  array{cache: string, path: string, changed: string}  $stale
     */
    private function warnOnce(array $stale, string $message): void
    {
        $directory = (string) $this->config->get('compiled');
        $marker = $directory.DIRECTORY_SEPARATOR.'stale-'.substr(hash('xxh128', $stale['path'].@filemtime($stale['path']).$stale['changed'].@filemtime($stale['changed'])), 0, 16);

        if (is_file($marker)) {
            return;
        }

        $this->app->make('log')->warning('Nitro: '.$message);
        @mkdir($directory, 0777, true);
        @touch($marker);
    }

    private function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $this->app->basePath()), '/').'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
