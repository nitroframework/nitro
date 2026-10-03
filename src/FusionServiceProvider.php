<?php

namespace Nitro\Fusion;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Nitro\Fusion\Blade\FusionTags;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Console\BuildCommand;
use Nitro\Fusion\Console\ClearCommand;
use Nitro\Fusion\Http\AssetController;
use Nitro\Fusion\Http\FillPortals;
use Nitro\Fusion\Http\PersistentMiddleware;
use Nitro\Fusion\Http\UpdateController;
use Nitro\Fusion\Http\UploadController;
use Nitro\Fusion\Rendering\Renderer;
use Nitro\Fusion\Testing\AssertableFusion;

/**
 * Fusion in a Laravel application (and so in Nitro, which is built on Laravel): only public
 * Laravel APIs.
 */
class FusionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/fusion.php', 'fusion');
        $this->mergeConfigFrom(dirname(__DIR__).'/config/nprogress.php', 'nprogress');

        $this->app->scoped(Renderer::class, fn (Application $app) => new Renderer($app->make('view')));

        $this->app->scoped(Registry::class, fn (Application $app) => new Registry(
            (array) $app->make('config')->get('fusion.components'),
            (array) $app->make('config')->get('fusion.pages'),
            $app->bootstrapPath('cache/fusion.php'),
        ));

        $this->app->scoped(Fusion::class, fn (Application $app) => new Fusion(
            $app,
            $app->make('config')->get('fusion', []),
            $app->make(Registry::class),
            $app->make(Renderer::class),
        ));

        $this->app->scoped(Bundle::class, fn (Application $app) => new Bundle(
            $app->make(Fusion::class),
            $app->make('view'),
            $app->make('blade.compiler'),
            $app->make('files'),
            (string) $app->make('config')->get('fusion.compiled'),
            (array) $app->make('config')->get('fusion.esbuild', []),
            $app->make('router'),
            (bool) $app->make('config')->get('fusion.minify_on_change', true),
            ...self::staticDirectory($app),
            obfuscate: (bool) $app->make('config')->get('fusion.build.obfuscate', true),
            rendering: (string) $app->make('config')->get('fusion.rendering', 'clone'),
            navigation: (string) $app->make('config')->get('fusion.navigation', 'data'),
        ));

        $this->app->bind(PersistentMiddleware::class, fn (Application $app) => new PersistentMiddleware(
            $app,
            $app->make('router'),
            $app->make('config')->get('fusion.persistent_middleware', []),
        ));
    }

    /**
     * fusion.static: the directory fusion:build publishes to, and its path under public/.
     *
     * @return array{staticDirectory: ?string, staticPath: string}
     */
    private static function staticDirectory(Application $app): array
    {
        $path = $app->make('config')->get('fusion.static', 'fusion');

        return is_string($path) && trim($path, '/') !== ''
            ? ['staticDirectory' => $app->publicPath(trim($path, '/')), 'staticPath' => trim($path, '/')]
            : ['staticDirectory' => null, 'staticPath' => 'fusion'];
    }

    public function boot(): void
    {
        $this->routes();
        $this->blade();
        $this->testing();

        /** The @portal placeholders in any web response get the content teleported to them. */
        $this->app->make('router')->pushMiddlewareToGroup('web', FillPortals::class);

        /**
         * Route::fusion('/students/{student}', Pages\Students\Show::class), or 'pages::students.show':
         * a page; mount() is the action, so its parameters bind like a controller's.
         */
        $app = $this->app;
        Router::macro('fusion', function (string $uri, string $page) use ($app) {
            $registry = $app->make(Registry::class);
            $class = class_exists($page) ? ltrim($page, '\\') : ($registry->class($page) ?? throw new InvalidArgumentException("Route::fusion(): there is no page [{$page}]."));

            if (! $registry->isPageClass($class)) {
                throw new InvalidArgumentException("Route::fusion() routes pages: {$class} is a component. Pages live in the pages namespace (fusion.pages); components are placed in views with <fusion:...>.");
            }

            return Route::get($uri, method_exists($class, 'mount') ? [$class, 'mount'] : [$class, 'callAction']);
        });

        if ($this->app->runningInConsole()) {
            /** fusion:build is its own step (as `vite build` is), not part of `optimize`. */
            $this->commands([BuildCommand::class, ClearCommand::class]);

            $this->publishes([dirname(__DIR__).'/config/fusion.php' => config_path('fusion.php')], 'fusion-config');
            $this->publishes([dirname(__DIR__).'/config/nprogress.php' => config_path('nprogress.php')], ['fusion-config', 'nprogress-config']);
        }
    }

    private function routes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $config = $this->app->make('config');
        $endpoint = rtrim((string) $config->get('fusion.endpoint', '/_fusion'), '/');

        Route::middleware($config->get('fusion.middleware', ['web']))
            ->post($endpoint.'/update', UpdateController::class)
            ->name('fusion.update');

        /** Files for a fusion:model file input, and previews of them ($upload->temporaryUrl()). */
        Route::middleware($config->get('fusion.middleware', ['web']))
            ->post($endpoint.'/upload', [UploadController::class, 'store'])
            ->middleware('throttle:'.$config->get('fusion.uploads.throttle', '60,1'))
            ->name('fusion.upload');
        Route::get($endpoint.'/upload/preview', [UploadController::class, 'preview'])->name('fusion.upload.preview');

        Route::get($endpoint.'/fusion.js', [AssetController::class, 'runtime'])->name('fusion.runtime');
        Route::get($endpoint.'/app.js', [AssetController::class, 'bundle'])->name('fusion.bundle');
        Route::get($endpoint.'/app/{group}.js', [AssetController::class, 'chunk'])->where('group', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('fusion.chunk');
    }

    /** $response->assertFusion(...) and $response->fusionState(...) in tests, as Inertia's assertInertia(). */
    private function testing(): void
    {
        if (! class_exists(TestResponse::class)) {
            return;
        }

        TestResponse::macro('assertFusion', function (?Closure $callback = null) {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            $page = AssertableFusion::fromResponse($this);

            if ($callback !== null) {
                $callback($page);
            }

            return $this;
        });

        TestResponse::macro('fusionState', function (?string $key = null) {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            return AssertableFusion::fromResponse($this)->state($key);
        });
    }

    private function blade(): void
    {
        Blade::precompiler(FusionTags::compile(...));

        Blade::directive('fusionScripts', fn () => '<?php echo app(\Nitro\Fusion\Fusion::class)->scripts(); ?>');
        Blade::directive('fusion', fn (string $expression) => "<?php echo app(\\Nitro\\Fusion\\Fusion::class)->mount({$expression}); ?>");

        /** Viewi's portals, as Livewire's @teleport: @portal('name') is where content goes, @teleport('name') what goes there. */
        Blade::directive('portal', fn (string $expression) => "<?php echo app(\\Nitro\\Fusion\\Fusion::class)->portal({$expression}); ?>");
        Blade::directive('teleport', fn (string $expression) => "<?php echo app(\\Nitro\\Fusion\\Fusion::class)->startTeleport({$expression}); ?>");
        Blade::directive('endteleport', fn () => '<?php echo app(\Nitro\Fusion\Fusion::class)->endTeleport(); ?>');

        /** @deferred('stats') ... @placeholder ... @enddeferred: what a page shows once its deferred properties are loaded, and meanwhile. */
        Blade::directive('deferred', fn (string $expression) => "<?php if (! \$this->fusion()->deferring([{$expression}])): ?>");
        Blade::directive('placeholder', fn () => '<?php else: ?>');
        Blade::directive('enddeferred', fn () => '<?php endif; ?>');
    }
}
