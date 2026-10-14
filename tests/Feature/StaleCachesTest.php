<?php

namespace Nitro\Tests\Feature;

use Illuminate\Http\Request;
use Nitro\Http\StaleCaches;
use Nitro\Support\Config;
use Nitro\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's route and config caches older than what they cache (StaleCaches): an exception
 * naming the fix in development; in production the request goes on and the log says so, once.
 */
class StaleCachesTest extends TestCase
{
    /**
     * A cache file written long before the files it caches, and the app as one that loaded it
     * (Laravel decides that once, at boot: routes.cached, config_loaded_from_cache).
     */
    private function staleCache(string $path): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, '<?php return [];');
        touch($path, mktime(0, 0, 0, 1, 1, 2000));
        $this->app->instance($path === $this->app->getCachedRoutesPath() ? 'routes.cached' : 'config_loaded_from_cache', true);
    }

    private function middleware(bool $debug): StaleCaches
    {
        return new StaleCaches($this->app, new Config((array) config('nitro'), $debug));
    }

    private function answer(StaleCaches $middleware): Response
    {
        return $middleware->handle(Request::create('/'), fn () => new Response('ok'));
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            foreach ([$this->app->getCachedRoutesPath(), $this->app->getCachedConfigPath(), ...glob(config('nitro.compiled').'/stale-*') ?: []] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_in_development_a_stale_route_cache_is_an_exception_naming_the_fix(): void
    {
        $this->boot('laravel');
        $this->staleCache($this->app->getCachedRoutesPath());

        try {
            $this->answer($this->middleware(true));
            $this->fail('Expected the stale route cache to be reported');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Laravel's route cache (bootstrap/cache/", $e->getMessage());
            $this->assertStringContainsString('is older than routes/', $e->getMessage());
            $this->assertStringContainsString('php artisan optimize:clear', $e->getMessage());
        }
    }

    public function test_in_development_a_stale_config_cache_is_an_exception(): void
    {
        $this->boot('laravel');
        $this->staleCache($this->app->getCachedConfigPath());

        $this->expectExceptionMessage("Laravel's config cache");
        $this->answer($this->middleware(true));
    }

    public function test_in_production_the_request_goes_on_and_the_log_says_so_once(): void
    {
        $this->boot('laravel');
        $this->staleCache($this->app->getCachedRoutesPath());
        $log = new class
        {
            /** @var list<string> */
            public array $warnings = [];

            public function warning(string $message): void
            {
                $this->warnings[] = $message;
            }
        };
        $this->app->instance('log', $log);
        $middleware = $this->middleware(false);

        $this->assertSame('ok', $this->answer($middleware)->getContent());
        $this->assertSame('ok', $this->answer($middleware)->getContent());

        $this->assertCount(1, $log->warnings, 'once, not on every request');
        $this->assertStringContainsString("Laravel's route cache", $log->warnings[0]);
    }

    public function test_caches_newer_than_what_they_cache_and_no_caches_change_nothing(): void
    {
        $this->boot('laravel');
        $middleware = $this->middleware(true);

        $this->assertSame('ok', $this->answer($middleware)->getContent(), 'no caches');

        $this->staleCache($this->app->getCachedRoutesPath());
        touch($this->app->getCachedRoutesPath(), time() + 60);

        $this->assertSame('ok', $this->answer($middleware)->getContent(), 'a cache made after its files');
    }
}
