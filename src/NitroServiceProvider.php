<?php

namespace Nitro;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Nitro\Blade\NitroTags;
use Nitro\Build\BrowserNames;
use Nitro\Build\Bundle;
use Nitro\Build\ServerChildren;
use Nitro\Compiler\ComponentMeta;
use Nitro\Compiler\Components;
use Nitro\Console\BuildCommand;
use Nitro\Console\ClearCommand;
use Nitro\Console\MakeCommand;
use Nitro\Console\SsrCommand;
use Nitro\Http\AssetController;
use Nitro\Http\FinishPage;
use Nitro\Http\PageResponses;
use Nitro\Http\PersistentMiddleware;
use Nitro\Http\ServerTiming;
use Nitro\Http\UpdateController;
use Nitro\Http\UploadController;
use Nitro\Support\ComponentHooks;
use Nitro\Lifecycle\Features;
use Nitro\Rendering\ComponentRenderer;
use Nitro\Rendering\PageData;
use Nitro\Rendering\PageHead;
use Nitro\Rendering\Placement;
use Nitro\Rendering\Portals;
use Nitro\Rendering\Scripts;
use Nitro\Rendering\Tags;
use Nitro\State\MethodCache;
use Nitro\State\SharedData;
use Nitro\Support\Signer;
use Nitro\State\Snapshots;
use Nitro\State\Stores;
use Nitro\Support\Config;
use Nitro\Support\Protocol;
use Nitro\View\BladeEngine;
use Nitro\View\ColocatedViews;
use Nitro\View\ScriptEngine;
use Nitro\View\ServerRendering\NodeRenderer;
use Nitro\View\ServerRendering\NullRenderer;
use Nitro\View\ServerRendering\ServerRenderer;
use Nitro\View\ViewEngines;
use Nitro\Support\Registry;
use Nitro\Testing\AssertableNitro;

/**
 * Nitro in a Laravel application (and so in Nitro, which is built on Laravel): only public
 * Laravel APIs.
 */
class NitroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->underlayConfig(dirname(__DIR__).'/config/nitro.php', 'nitro');
        $this->underlayConfig(dirname(__DIR__).'/config/nprogress.php', 'nprogress');

        /** JavaScript views in the first HTML (nitro.ssr): the app's render service, or none. Per request: a service that failed is not asked again. */
        $this->app->scoped(ServerRenderer::class, function (Application $app) {
            $ssr = (array) $app->make('config')->get('nitro.ssr');

            if (! $ssr['enabled']) {
                return new NullRenderer;
            }

            return new NodeRenderer(
                (string) $ssr['url'],
                (float) $ssr['timeout'],
                $app->bound('log') ? $app->make('log') : null,
            );
        });

        /** What Nitro signs, per request: its key is bound to the request's session and user. */
        $this->app->scoped(Signer::class, fn (Application $app) => new Signer($app));

        /** The state the browser holds, sealed and opened with that signer. */
        $this->app->scoped(Snapshots::class, fn (Application $app) => new Snapshots(
            $app->make(Signer::class),
            $app->make(BrowserNames::class),
            $app->make(Components::class),
            ($lifetime = $app->make('config')->get('nitro.state.lifetime')) === null ? null : (int) $lifetime,
        ));

        /** #[Cached] results, in nitro.cache.store (the application's default store when null). */
        $this->app->scoped(MethodCache::class, fn (Application $app) => new MethodCache(
            $app,
            $app->make('cache')->store($app->make('config')->get('nitro.cache.store')),
        ));

        $this->app->scoped(ViewEngines::class, function (Application $app) {
            $engines = new ViewEngines;
            $engines->add(new BladeEngine($app->make('view'), $app->make('blade.compiler')));

            foreach ((array) $app->make('config')->get('nitro.views') as $name => $view) {
                $engines->add($this->scriptEngine($app, (string) $name, (array) $view));
            }

            /** Each namespace's views, for their engines: nitro.namespaces.issues.views.vue. */
            foreach ((array) $app->make('config')->get('nitro.namespaces') as $namespace => $places) {
                foreach ((array) ($places['views'] ?? []) as $name => $paths) {
                    $engine = $engines->named((string) $name);

                    if (! $engine instanceof ScriptEngine) {
                        throw new InvalidArgumentException("nitro.namespaces.{$namespace}.views.{$name}: there is no [{$name}] in nitro.views. A namespace's views are for the engines nitro.views lists (vue, react, solid, svelte, or the app's own).");
                    }

                    $engine->addNamespace((string) $namespace, array_values((array) $paths));
                }
            }

            return $engines;
        });

        $this->app->scoped(ComponentRenderer::class, fn (Application $app) => new ComponentRenderer($app->make('view'), $app->make(ViewEngines::class), $app->make(BrowserNames::class), $app->make(Features::class), $app, $app->make(PageData::class)));

        $this->app->scoped(Registry::class, fn (Application $app) => new Registry(
            (array) $app->make('config')->get('nitro.components'),
            (array) $app->make('config')->get('nitro.pages'),
            $app->bootstrapPath(Registry::CACHE),
            $app->make('config')->get('nitro.stores'),
            fn () => $app->make(ColocatedViews::class)->components(),
            (array) $app->make('config')->get('nitro.namespaces'),
        ));

        /** Components written in their JavaScript views: their classes go with the compiled bundle. */
        $this->app->scoped(ColocatedViews::class, fn (Application $app) => new ColocatedViews(
            $app->make(ViewEngines::class),
            rtrim((string) $app->make('config')->get('nitro.compiled'), '/\\').DIRECTORY_SEPARATOR.'views',
        ));

        /** Nitro's parts, one each per request: each takes what it needs in its constructor. */
        foreach ([PageData::class, BrowserNames::class, ServerChildren::class, Placement::class, SharedData::class, Stores::class, Tags::class, Portals::class, PageHead::class, Scripts::class, PageResponses::class] as $part) {
            $this->app->scoped($part);
        }

        $this->app->scoped(Nitro::class, fn (Application $app) => new Nitro($app));

        $this->app->scoped(Config::class, fn (Application $app) => new Config((array) $app->make('config')->get('nitro'), (bool) $app->make('config')->get('app.debug')));

        $this->app->scoped(Bundle::class, fn (Application $app) => new Bundle(
            $app->make(Config::class),
            fn (ComponentMeta $meta) => $app->make(PageHead::class)->forClientPage($meta),
            $app->make(Registry::class),
            $app->make(Components::class),
            $app->make(ViewEngines::class),
            $app->make(Signer::class),
            $app->make('blade.compiler'),
            $app->make('files'),
            (string) $app->make('config')->get('nitro.compiled'),
            (array) $app->make('config')->get('nitro.esbuild'),
            $app->make('router'),
            (bool) $app->make('config')->get('nitro.minify_on_change'),
            ...self::staticDirectory($app),
            obfuscate: (bool) $app->make('config')->get('nitro.build.obfuscate'),
            rendering: (string) $app->make('config')->get('nitro.rendering'),
            navigation: NavigationMode::fromConfig((string) $app->make('config')->get('nitro.navigation')),
        ));

        /** The server lifecycle: Nitro's own features, then the application's (nitro.features). */
        $this->app->scoped(Features::class, fn (Application $app) => new Features(
            $app,
            (array) $app->make('config')->get('nitro.features'),
        ));

        $this->app->bind(PersistentMiddleware::class, fn (Application $app) => new PersistentMiddleware(
            $app,
            $app->make('router'),
            $app->make('config')->get('nitro.persistent_middleware'),
        ));

        $this->routeMacro();
    }

    /**
     * Route::nitro('/posts/{post}', Pages\Posts\Show::class), or 'pages::posts.show': a page;
     * mount() is the action, so its parameters bind like a controller's. Defined when Nitro is
     * registered, so a provider that boots before Nitro's can route pages in its boot().
     */
    private function routeMacro(): void
    {
        Router::macro('nitro', function (string $uri, string $page) {
            $registry = app(Registry::class);
            $class = class_exists($page) ? ltrim($page, '\\') : ($registry->class($page) ?? throw new InvalidArgumentException("Route::nitro(): there is no page [{$page}]."));

            if ($registry->isStoreClass($class)) {
                throw new InvalidArgumentException("Route::nitro() routes pages: {$class} is a store, which components use through a property of its type.");
            }

            if (! $registry->isPageClass($class)) {
                throw new InvalidArgumentException("Route::nitro() routes pages: {$class} is a component. Pages live in the pages namespace (nitro.pages); components are placed in views with <nitro:...>.");
            }

            return Route::get($uri, method_exists($class, ComponentHooks::MOUNT) ? [$class, ComponentHooks::MOUNT] : [$class, 'callAction']);
        });
    }

    /**
     * Nitro's config file under the app's: the app's values win, key by key at every depth, so a
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
     * nitro.static: the directory nitro:build publishes to, and its path under public/.
     *
     * @return array{staticDirectory: ?string, staticPath: string}
     */
    private static function staticDirectory(Application $app): array
    {
        $path = $app->make('config')->get('nitro.static');

        return is_string($path) && trim($path, '/') !== ''
            ? ['staticDirectory' => $app->publicPath(trim($path, '/')), 'staticPath' => trim($path, '/')]
            : ['staticDirectory' => null, 'staticPath' => 'nitro'];
    }

    /**
     * Nitro's middleware in the web group: the page's data block and @portal content filled in any
     * web answer (FinishPage), and with the dev overlay on, each answer's time on the server and its
     * database's (ServerTiming). Pushed to the router's group now and whenever an HTTP kernel is
     * made: a kernel made after boot (as in an app's tests) sets the router's groups from its own.
     */
    private function webMiddleware(): void
    {
        $push = function () {
            foreach ([FinishPage::class, ServerTiming::class] as $middleware) {
                $this->app->make('router')->pushMiddlewareToGroup('web', $middleware);
            }
        };

        $push();
        $this->app->afterResolving(HttpKernel::class, $push);
    }

    public function boot(): void
    {
        $this->routes();
        $this->blade();
        $this->testing();

        $this->webMiddleware();

        if ($this->app->runningInConsole()) {
            /** nitro:build is its own step (as `vite build` is), not part of `optimize`. */
            $this->commands([BuildCommand::class, ClearCommand::class, SsrCommand::class, MakeCommand::class]);
            /** make:nitro's templates, for an app to change: it reads stubs/nitro.*.stub first. */
            $this->publishes([dirname(__DIR__).'/resources/stubs' => base_path('stubs')], 'nitro-stubs');

            $this->publishes([dirname(__DIR__).'/config/nitro.php' => config_path('nitro.php')], 'nitro-config');
            $this->publishes([dirname(__DIR__).'/config/nprogress.php' => config_path('nprogress.php')], ['nitro-config', 'nprogress-config']);
        }
    }

    /**
     * The engine for a nitro.views entry: its adapter (Nitro's VueAdapter, or a class of the
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
            throw new InvalidArgumentException("nitro.views.{$name}: the adapter [{$view['adapter']}] is not a view engine named [{$name}]. An adapter extends Nitro\\View\\ScriptEngine, as VueAdapter does.");
        }

        return $engine;
    }

    private function routes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $config = $this->app->make('config');
        $endpoint = rtrim((string) $config->get('nitro.endpoint'), '/');

        $path = fn (string $role) => $endpoint.'/'.Protocol::path($role);

        Route::middleware($config->get('nitro.middleware'))
            ->post($path('update'), UpdateController::class)
            ->name('nitro.update');

        /** Files for a nitro:model file input, and previews of them ($upload->temporaryUrl()). */
        Route::middleware($config->get('nitro.middleware'))
            ->post($path('upload'), [UploadController::class, 'store'])
            ->middleware('throttle:'.$config->get('nitro.uploads.throttle'))
            ->name('nitro.upload');
        /** In the session: an upload's reference is signed for the session (Signer). */
        Route::middleware($config->get('nitro.middleware'))
            ->get($path('preview'), [UploadController::class, 'preview'])
            ->name('nitro.upload.preview');

        Route::get($path('runtime'), [AssetController::class, 'runtime'])->name('nitro.runtime');
        Route::get($path('devtools'), [AssetController::class, 'devtools'])->name('nitro.devtools');
        Route::get($path('bundle'), [AssetController::class, 'bundle'])->name('nitro.bundle');
        Route::get($path('chunk'), [AssetController::class, 'chunk'])->where('id', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('nitro.chunk');
    }

    /** $response->assertNitro(...) and $response->nitroState(...) in tests, as Inertia's assertInertia(). */
    private function testing(): void
    {
        if (! class_exists(TestResponse::class)) {
            return;
        }

        TestResponse::macro('assertNitro', function (?Closure $callback = null) {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            $page = AssertableNitro::fromResponse($this);

            if ($callback !== null) {
                $callback($page);
            }

            return $this;
        });

        TestResponse::macro('nitroState', function (?string $key = null) {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            return AssertableNitro::fromResponse($this)->state($key);
        });
    }

    /**
     * Compiled Blade views hold what Nitro's directives and <nitro:> tags compile to: the parts
     * they call by name. Laravel compiles a view again only when its own file changes, so views
     * compiled by another version of Nitro (an upgrade) are dropped once, to be compiled again.
     * The version is that of the two files that write them: this provider and NitroTags.
     */
    private function forgetStaleViews(): void
    {
        $compiled = (string) $this->app->make('config')->get('view.compiled');

        if ($compiled === '' || ! is_dir($compiled)) {
            return;
        }

        $stamp = $compiled.DIRECTORY_SEPARATOR.'nitro.version';
        $version = md5((string) md5_file(__FILE__).md5_file(__DIR__.'/Blade/NitroTags.php'));

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
        /** @nitroScript blocks are the browser's (Globals::SCRIPT): out before the tags are read. */
        Blade::precompiler(NitroTags::withoutScripts(...));
        Blade::precompiler(NitroTags::compile(...));

        Blade::directive('nitroScripts', fn () => '<?php echo app(\Nitro\Rendering\Scripts::class)->html(); ?>');

        /** @nitroHead in the layout's <head>: the page's <title> and its tags for search engines and link previews. */
        Blade::directive('nitroHead', fn () => '<?php echo app(\Nitro\Rendering\PageHead::class)->html(); ?>');
        Blade::directive('nitro', fn (string $expression) => "<?php echo app(\\Nitro\\Rendering\\Placement::class)->mount({$expression}); ?>");

        /** Viewi's portals, as Livewire's @teleport: @portal('name') is where content goes, @teleport('name') what goes there. */
        Blade::directive('portal', fn (string $expression) => "<?php echo app(\\Nitro\\Rendering\\Portals::class)->portal({$expression}); ?>");
        Blade::directive('teleport', fn (string $expression) => "<?php echo app(\\Nitro\\Rendering\\Portals::class)->startTeleport({$expression}); ?>");
        Blade::directive('endteleport', fn () => '<?php echo app(\Nitro\Rendering\Portals::class)->endTeleport(); ?>');

        /** @deferred('stats') ... @placeholder ... @enddeferred: what a page shows once its deferred properties are loaded, and meanwhile. */
        Blade::directive('deferred', fn (string $expression) => "<?php if (! \$this->nitro()->deferring([{$expression}])): ?>");
        Blade::directive('placeholder', fn () => '<?php else: ?>');
        Blade::directive('enddeferred', fn () => '<?php endif; ?>');
    }
}
