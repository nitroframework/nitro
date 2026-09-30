<?php

namespace Nitro\Fusion;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nitro\Fusion\Blade\FusionTags;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Console\BuildCommand;
use Nitro\Fusion\Console\ClearCommand;
use Nitro\Fusion\Http\AssetController;
use Nitro\Fusion\Http\FillPortals;
use Nitro\Fusion\Http\PersistentMiddleware;
use Nitro\Fusion\Http\UpdateController;
use Nitro\Fusion\Rendering\Renderer;

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
            $app->make('config')->get('fusion.components', []),
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
        ));

        $this->app->bind(PersistentMiddleware::class, fn (Application $app) => new PersistentMiddleware(
            $app,
            $app->make('router'),
            $app->make('config')->get('fusion.persistent_middleware', []),
        ));
    }

    public function boot(): void
    {
        $this->routes();
        $this->blade();

        /** The @portal placeholders in any web response get the content teleported to them. */
        $this->app->make('router')->pushMiddlewareToGroup('web', FillPortals::class);

        /** Route::fusion('/students/{student}', StudentPage::class): mount() is the action, so its parameters bind like a controller's. */
        Router::macro('fusion', fn (string $uri, string $component) => Route::get(
            $uri,
            method_exists($component, 'mount') ? [$component, 'mount'] : [$component, 'callAction'],
        ));

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

        Route::get($endpoint.'/fusion.js', [AssetController::class, 'runtime'])->name('fusion.runtime');
        Route::get($endpoint.'/app.js', [AssetController::class, 'bundle'])->name('fusion.bundle');
        Route::get($endpoint.'/app/{group}.js', [AssetController::class, 'chunk'])->where('group', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('fusion.chunk');
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
    }
}
