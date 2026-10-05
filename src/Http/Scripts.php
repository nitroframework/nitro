<?php

namespace Nitro\Fusion\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\HtmlString;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Rendering\PageHead;
use Nitro\Fusion\Rendering\Renderer;
use Nitro\Fusion\State\SharedData;
use Nitro\Fusion\Support\Wire;

/**
 * @fusionScripts: the runtime, the compiled components and the chunks this page's components are
 * in, and the globals the browser starts with (what its Laravel helpers name, the shared and
 * flash data, the progress bar's settings). A part of Fusion.
 */
final class Scripts
{
    public function __construct(
        private readonly Fusion $fusion,
        private readonly Container $app,
        private readonly Renderer $renderer,
        private readonly PageHead $head,
        private readonly SharedData $data,
    ) {
    }

    /** @fusionScripts: the runtime and the application's compiled components. */
    public function html(): HtmlString
    {
        if ($this->head->renderingTitle()) {
            return new HtmlString('');
        }

        $token = $this->app->bound('session.store') && $this->app->make('request')->hasSession() ? csrf_token() : '';
        $built = $this->app->make(Bundle::class);
        $tag = $built->tag();
        $urls = $this->app->make('url');

        /** fusion:build's static files when it has them for these components (served without PHP), else Fusion's routes. */
        $static = fn (?string $file) => $file === null ? null : $urls->asset($file);
        $runtime = e($static($built->staticRuntime()) ?? $this->fusion->endpoint('fusion.js').'?v='.$this->fusion->runtimeVersion());
        $bundle = e($static($built->staticFile()) ?? $this->fusion->endpoint('app.js').'?v='.$tag);
        $chunkUrl = $static($built->staticChunkPattern()) ?? $this->fusion->endpoint('app/{id}.js').'?v='.$tag;

        $progress = $this->progress();
        $progress = $progress === null ? '' : ' data-progress="'.e(json_encode($progress, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)).'"';

        /** The chunks of the components on this page (pages and #[LazyLoad]), loaded with the bundle. */
        $inChunks = $built->uses()['chunks'] ?? [];
        $groups = array_unique(array_filter(array_map(fn (string $name) => $inChunks[$name] ?? null, $this->renderer->rendered())));
        $chunks = array_map(
            fn (string $group) => "\n".'<script src="'.e(str_replace('{id}', rawurlencode($built->chunkId($group)), $chunkUrl)).'" defer></script>',
            $groups,
        );

        $globals = json_encode($this->globals(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return new HtmlString(
            '<script type="application/json" data-fusion-globals>'.$globals.'</script>'."\n"
            .'<script src="'.$runtime.'" data-endpoint="'.e($this->fusion->endpoint()).'" data-bundle="'.e($tag).'" data-chunks="'.e($chunkUrl).'" data-csrf="'.e($token).'"'.$progress.' defer></script>'."\n"
            .'<script src="'.$bundle.'" defer></script>'.implode('', $chunks)
        );
    }

    /**
     * What the browser's Laravel helpers need, for what the compiled code names only (the
     * bundle's uses): the named routes it generates, its translations in this request's locale,
     * the public config it reads, and the URLs url() and asset() start from.
     *
     * @return array<string, mixed>
     */
    public function globals(): array
    {
        $uses = $this->app->make(Bundle::class)->uses();
        $urls = $this->app->make('url');

        $routes = [];
        foreach ($uses['routes'] as $name) {
            $route = $this->app->make('router')->getRoutes()->getByName($name);

            if ($route !== null) {
                /** uri() without the binding fields ({post:slug} is {post} there): those come apart. */
                $routes[$name] = array_filter([
                    'uri' => $route->uri(),
                    'bindings' => $route->bindingFields() === [] ? null : $route->bindingFields(),
                    'domain' => $route->getDomain(),
                ], fn ($v) => $v !== null);
            }
        }

        $lang = [];
        foreach ($uses['lang'] as $key) {
            $line = $this->app->make('translator')->get($key);

            /** A key without a line is its own line: nothing to ship. */
            if (is_string($line) && $line !== $key) {
                $lang[$key] = $line;
            }
        }

        $config = [];
        foreach ($uses['config'] as $key) {
            $config[$key] = Wire::encode($this->app->make('config')->get($key));
        }

        return [
            'url' => $urls->to('/'),
            'asset' => rtrim($urls->asset('/'), '/'),
            'locale' => $this->app->make('translator')->getLocale(),
            'routes' => (object) $routes,
            'lang' => (object) $lang,
            'config' => (object) $config,
            'shared' => $this->data->sharedForBrowser(),
            'once' => (object) $this->data->onceForBrowser(),
            'flash' => $this->data->flashForBrowser(),
        ];
    }

    /**
     * The progress bar's settings for the browser (config/nprogress.php), null when it is off.
     *
     * @return array<string, mixed>|null
     */
    private function progress(): ?array
    {
        $config = (array) $this->app->make('config')->get('nprogress', []);

        if (! filter_var($config['show'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        return [
            'serverCalls' => filter_var($config['server_calls'] ?? false, FILTER_VALIDATE_BOOL),
            'delay' => (int) ($config['delay'] ?? 0),
            'minimumDuration' => (int) ($config['minimum_duration'] ?? 0),
            'color' => (string) ($config['color'] ?? '#29d'),
            'height' => (string) ($config['height'] ?? '3px'),
            'position' => ($config['position'] ?? 'top') === 'bottom' ? 'bottom' : 'top',
            'glow' => filter_var($config['glow'] ?? true, FILTER_VALIDATE_BOOL),
            'glowWidth' => (string) ($config['glow_width'] ?? '100px'),
            'spinner' => filter_var($config['spinner'] ?? false, FILTER_VALIDATE_BOOL),
            'spinnerSize' => (string) ($config['spinner_size'] ?? '18px'),
            'zIndex' => (int) ($config['z_index'] ?? 1031),
            'minimum' => (float) ($config['minimum'] ?? 0.15),
            'maximum' => (float) ($config['maximum'] ?? 0.92),
            'easing' => (string) ($config['easing'] ?? 'ease-out'),
            'speed' => (int) ($config['speed'] ?? 150),
            'fadeSpeed' => (int) ($config['fade_speed'] ?? 200),
            'trickle' => filter_var($config['trickle'] ?? true, FILTER_VALIDATE_BOOL),
            'trickleSpeed' => (int) ($config['trickle_speed'] ?? 100),
        ];
    }
}
