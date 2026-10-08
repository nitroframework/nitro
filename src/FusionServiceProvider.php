<?php

namespace Nitro\Fusion;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Nitro\Fusion\Blade\FusionTags;
use Nitro\Fusion\Build\BrowserNames;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Build\ServerChildren;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Console\BuildCommand;
use Nitro\Fusion\Console\ClearCommand;
use Nitro\Fusion\Console\SsrCommand;
use Nitro\Fusion\Http\AssetController;
use Nitro\Fusion\Http\FillPortals;
use Nitro\Fusion\Http\PageResponses;
use Nitro\Fusion\Http\PersistentMiddleware;
use Nitro\Fusion\Http\UpdateController;
use Nitro\Fusion\Http\UploadController;
use Nitro\Fusion\Support\ComponentHooks;
use Nitro\Fusion\Lifecycle\Features;
use Nitro\Fusion\Rendering\ComponentRenderer;
use Nitro\Fusion\Rendering\PageHead;
use Nitro\Fusion\Rendering\Placement;
use Nitro\Fusion\Rendering\Portals;
use Nitro\Fusion\Rendering\Scripts;
use Nitro\Fusion\Rendering\Tags;
use Nitro\Fusion\State\SharedData;
use Nitro\Fusion\Support\Signer;
use Nitro\Fusion\State\Snapshots;
use Nitro\Fusion\State\Stores;
use Nitro\Fusion\Support\Config;
use Nitro\Fusion\Support\Protocol;
use Nitro\Fusion\View\BladeEngine;
use Nitro\Fusion\View\ColocatedViews;
use Nitro\Fusion\View\ScriptEngine;
use Nitro\Fusion\View\ServerRendering\NodeRenderer;
use Nitro\Fusion\View\ServerRendering\NullRenderer;
use Nitro\Fusion\View\ServerRendering\ServerRenderer;
use Nitro\Fusion\View\ViewEngines;
use Nitro\Fusion\Support\Registry;
use Nitro\Fusion\Testing\AssertableFusion;

/**
 * Fusion in a Laravel application (and so in Nitro, which is built on Laravel): only public
 * Laravel APIs.
 */
class FusionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->underlayConfig(dirname(__DIR__).'/config/fusion.php', 'fusion');
        $this->underlayConfig(dirname(__DIR__).'/config/nprogress.php', 'nprogress');

        /** JavaScript views in the first HTML (fusion.ssr): the app's render service, or none. Per request: a service that failed is not asked again. */
        $this->app->scoped(ServerRenderer::class, function (Application $app) {
            $ssr = (array) $app->make('config')->get('fusion.ssr');

            if (! $ssr['enabled']) {
                return new NullRenderer;
            }

            return new NodeRenderer(
                (string) $ssr['url'],
                (float) $ssr['timeout'],
                $app->bound('log') ? $app->make('log') : null,
            );
        });

        /** What Fusion signs, per request: its key is bound to the request's session and user. */
        $this->app->scoped(Signer::class, fn (Application $app) => new Signer($app));

        /** The state the browser holds, sealed and opened with that signer. */
        $this->app->scoped(Snapshots::class, fn (Application $app) => new Snapshots(
            $app->make(Signer::class),
            $app->make(BrowserNames::class),
            ($lifetime = $app->make('config')->get('fusion.state.lifetime')) === null ? null : (int) $lifetime,
        ));

        $this->app->scoped(ViewEngines::class, function (Application $app) {
            $engines = new ViewEngines;
            $engines->add(new BladeEngine($app->make('view'), $app->make('blade.compiler')));

            foreach ((array) $app->make('config')->get('fusion.views') as $name => $view) {
                $engines->add($this->scriptEngine($app, (string) $name, (array) $view));
            }

            return $engines;
        });

        $this->app->scoped(ComponentRenderer::class, fn (Application $app) => new ComponentRenderer($app->make('view'), $app->make(ViewEngines::class), $app->make(BrowserNames::class), $app->make(Features::class), $app));

        $this->app->scoped(Registry::class, fn (Application $app) => new Registry(
            (array) $app->make('config')->get('fusion.components'),
            (array) $app->make('config')->get('fusion.pages'),
            $app->bootstrapPath(Registry::CACHE),
            $app->make('config')->get('fusion.stores'),
            fn () => $app->make(ColocatedViews::class)->components(),
        ));

        /** Components written in their JavaScript views: their classes go with the compiled bundle. */
        $this->app->scoped(ColocatedViews::class, fn (Application $app) => new ColocatedViews(
            $app->make(ViewEngines::class),
            rtrim((string) $app->make('config')->get('fusion.compiled'), '/\\').DIRECTORY_SEPARATOR.'views',
        ));

        /** Fusion's parts, one each per request: each takes what it needs in its constructor. */
        foreach ([BrowserNames::class, ServerChildren::class, Placement::class, SharedData::class, Stores::class, Tags::class, Portals::class, PageHead::class, Scripts::class, PageResponses::class] as $part) {
            $this->app->scoped($part);
        }

        $this->app->scoped(Fusion::class, fn (Application $app) => new Fusion($app));

        $this->app->scoped(Config::class, fn (Application $app) => new Config((array) $app->make('config')->get('fusion')));

        $this->app->scoped(Bundle::class, fn (Application $app) => new Bundle(
            $app->make(Config::class),
            fn (ComponentMeta $meta) => $app->make(PageHead::class)->forClientPage($meta),
            $app->make(Registry::class),
            $app->make(Components::class),
            $app->make(ViewEngines::class),
            $app->make(Signer::class),
            $app->make('blade.compiler'),
            $app->make('files'),
            (string) $app->make('config')->get('fusion.compiled'),
            (array) $app->make('config')->get('fusion.esbuild'),
            $app->make('router'),
            (bool) $app->make('config')->get('fusion.minify_on_change'),
            ...self::staticDirectory($app),
            obfuscate: (bool) $app->make('config')->get('fusion.build.obfuscate'),
            rendering: (string) $app->make('config')->get('fusion.rendering'),
            navigation: NavigationMode::fromConfig((string) $app->make('config')->get('fusion.navigation')),
        ));

        /** The server lifecycle: Fusion's own features, then the application's (fusion.features). */
        $this->app->scoped(Features::class, fn (Application $app) => new Features(
            $app,
            (array) $app->make('config')->get('fusion.features'),
        ));

        $this->app->bind(PersistentMiddleware::class, fn (Application $app) => new PersistentMiddleware(
            $app,
            $app->make('router'),
            $app->make('config')->get('fusion.persistent_middleware'),
        ));
    }

    /**
     * Fusion's config file under the app's: the app's values win, key by key at every depth, so a
     * config published before a key existed has it all the same, and the code reads every value
     * from config alone (the files in config/ are the one place its defaults are). A list
     * (middleware, paths, rules) is the app's whole, not merged entry by entry.
     */
    private function underlayConfig(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $config->set($key, self::underlay(require $path, (array) $config->get($key, [])));
    }

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function underlay(array $defaults, array $values): array
    {
        foreach ($values as $name => $value) {
            $default = $defaults[$name] ?? null;
            $defaults[$name] = is_array($value) && is_array($default) && ! array_is_list($value) && ! array_is_list($default)
                ? self::underlay($default, $value)
                : $value;
        }

        return $defaults;
    }

    /**
     * fusion.static: the directory fusion:build publishes to, and its path under public/.
     *
     * @return array{staticDirectory: ?string, staticPath: string}
     */
    private static function staticDirectory(Application $app): array
    {
        $path = $app->make('config')->get('fusion.static');

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
        Router::macro('fusion', function (string $uri, string $page) {
            $registry = app(Registry::class);
            $class = class_exists($page) ? ltrim($page, '\\') : ($registry->class($page) ?? throw new InvalidArgumentException("Route::fusion(): there is no page [{$page}]."));

            if ($registry->isStoreClass($class)) {
                throw new InvalidArgumentException("Route::fusion() routes pages: {$class} is a store, which components use through a property of its type.");
            }

            if (! $registry->isPageClass($class)) {
                throw new InvalidArgumentException("Route::fusion() routes pages: {$class} is a component. Pages live in the pages namespace (fusion.pages); components are placed in views with <fusion:...>.");
            }

            return Route::get($uri, method_exists($class, ComponentHooks::MOUNT) ? [$class, ComponentHooks::MOUNT] : [$class, 'callAction']);
        });

        if ($this->app->runningInConsole()) {
            /** fusion:build is its own step (as `vite build` is), not part of `optimize`. */
            $this->commands([BuildCommand::class, ClearCommand::class, SsrCommand::class]);

            $this->publishes([dirname(__DIR__).'/config/fusion.php' => config_path('fusion.php')], 'fusion-config');
            $this->publishes([dirname(__DIR__).'/config/nprogress.php' => config_path('nprogress.php')], ['fusion-config', 'nprogress-config']);
        }
    }

    /**
     * The engine for a fusion.views entry: its adapter (Fusion's VueAdapter, or a class of the
     * app's own), made by the container so it may take what it needs; without one, a plain
     * ScriptEngine, whose views the app's JavaScript renders as it likes.
     *
     * @param  array{path: string|list<string>, extensions: string|list<string>, adapter?: class-string<ScriptEngine>}  $view
     */
    private function scriptEngine(Application $app, string $name, array $view): ScriptEngine
    {
        $paths = array_values((array) $view['path']);
        $extensions = array_values((array) $view['extensions']);
        $server = $app->make(ServerRenderer::class);

        if (! isset($view['adapter'])) {
            return new ScriptEngine($name, $paths, $extensions, $server);
        }

        $engine = $app->make($view['adapter'], ['paths' => $paths, 'extensions' => $extensions, 'server' => $server]);

        if (! $engine instanceof ScriptEngine || $engine->name() !== $name) {
            throw new InvalidArgumentException("fusion.views.{$name}: the adapter [{$view['adapter']}] is not a view engine named [{$name}]. An adapter extends Nitro\\Fusion\\View\\ScriptEngine, as VueAdapter does.");
        }

        return $engine;
    }

    private function routes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $config = $this->app->make('config');
        $endpoint = rtrim((string) $config->get('fusion.endpoint'), '/');

        $path = fn (string $role) => $endpoint.'/'.Protocol::path($role);

        Route::middleware($config->get('fusion.middleware'))
            ->post($path('update'), UpdateController::class)
            ->name('fusion.update');

        /** Files for a fusion:model file input, and previews of them ($upload->temporaryUrl()). */
        Route::middleware($config->get('fusion.middleware'))
            ->post($path('upload'), [UploadController::class, 'store'])
            ->middleware('throttle:'.$config->get('fusion.uploads.throttle'))
            ->name('fusion.upload');
        /** In the session: an upload's reference is signed for the session (Signer). */
        Route::middleware($config->get('fusion.middleware'))
            ->get($path('preview'), [UploadController::class, 'preview'])
            ->name('fusion.upload.preview');

        Route::get($path('runtime'), [AssetController::class, 'runtime'])->name('fusion.runtime');
        Route::get($path('bundle'), [AssetController::class, 'bundle'])->name('fusion.bundle');
        Route::get($path('chunk'), [AssetController::class, 'chunk'])->where('id', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('fusion.chunk');
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

    /**
     * Compiled Blade views hold what Fusion's directives and <fusion:> tags compile to: the parts
     * they call by name. Laravel compiles a view again only when its own file changes, so views
     * compiled by another version of Fusion (an upgrade) are dropped once, to be compiled again.
     * The version is that of the two files that write them: this provider and FusionTags.
     */
    private function forgetStaleViews(): void
    {
        $compiled = (string) $this->app->make('config')->get('view.compiled');

        if ($compiled === '' || ! is_dir($compiled)) {
            return;
        }

        $stamp = $compiled.DIRECTORY_SEPARATOR.'fusion.version';
        $version = md5((string) md5_file(__FILE__).md5_file(__DIR__.'/Blade/FusionTags.php'));

        if (@file_get_contents($stamp) === $version) {
            return;
        }

        foreach (glob($compiled.DIRECTORY_SEPARATOR.'*.php') ?: [] as $view) {
            @unlink($view);
        }

        @file_put_contents($stamp, $version);
    }

    private function blade(): void
    {
        $this->forgetStaleViews();
        Blade::precompiler(FusionTags::compile(...));

        Blade::directive('fusionScripts', fn () => '<?php echo app(\Nitro\Fusion\Rendering\Scripts::class)->html(); ?>');

        /** @fusionHead in the layout's <head>: the page's <title> and its tags for search engines and link previews. */
        Blade::directive('fusionHead', fn () => '<?php echo app(\Nitro\Fusion\Rendering\PageHead::class)->html(); ?>');
        Blade::directive('fusion', fn (string $expression) => "<?php echo app(\\Nitro\\Fusion\\Rendering\\Placement::class)->mount({$expression}); ?>");

        /** Viewi's portals, as Livewire's @teleport: @portal('name') is where content goes, @teleport('name') what goes there. */
        Blade::directive('portal', fn (string $expression) => "<?php echo app(\\Nitro\\Fusion\\Rendering\\Portals::class)->portal({$expression}); ?>");
        Blade::directive('teleport', fn (string $expression) => "<?php echo app(\\Nitro\\Fusion\\Rendering\\Portals::class)->startTeleport({$expression}); ?>");
        Blade::directive('endteleport', fn () => '<?php echo app(\Nitro\Fusion\Rendering\Portals::class)->endTeleport(); ?>');

        /** @deferred('stats') ... @placeholder ... @enddeferred: what a page shows once its deferred properties are loaded, and meanwhile. */
        Blade::directive('deferred', fn (string $expression) => "<?php if (! \$this->fusion()->deferring([{$expression}])): ?>");
        Blade::directive('placeholder', fn () => '<?php else: ?>');
        Blade::directive('enddeferred', fn () => '<?php endif; ?>');
    }
}
