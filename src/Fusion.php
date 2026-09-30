<?php

namespace Nitro\Fusion;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\Template\ChildTag;
use Nitro\Fusion\Rendering\Renderer;
use Nitro\Fusion\Rendering\Snapshot;
use Nitro\Fusion\Support\Hooks;
use Nitro\Fusion\Support\Naming;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fusion in an application: its configuration, its components, and rendering them on the server.
 * Everything else asks this class (the service provider binds it once per application).
 */
final class Fusion
{
    /** Sent by the browser's fusion:navigate visits: the page answers with data, not HTML. */
    public const NAVIGATE_HEADER = 'X-Fusion-Navigate';

    /** @var array<class-string, ComponentMeta> */
    private array $metas = [];

    /** @var array<class-string, bool> */
    private array $serverChildren = [];

    /**
     * <fusion:...> tags with content being rendered, innermost last: the slots they collect.
     *
     * @var list<array{name: string, props: array, key: mixed, number: int, slots: array<string, string>, slot: ?string}>
     */
    private array $tags = [];

    /**
     * Viewi's PortalScope, by portal name: whether @portal placed it, and the @teleport content
     * for it (with the order the browser hydrates it in).
     *
     * @var array<string, array{defined: bool, contents: list<array{order: int, seq: int, html: string}>}>
     */
    private array $portals = [];

    /** @var list<string> the @teleport blocks being rendered, innermost last: their portal names */
    private array $teleporting = [];

    public function __construct(
        private readonly Container $app,
        private readonly array $config,
        private readonly Registry $registry,
        private readonly Renderer $renderer,
    ) {
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    public function renderer(): Renderer
    {
        return $this->renderer;
    }

    /** The key that signs component state: derived from the application key. */
    public function key(): string
    {
        $appKey = (string) $this->app->make('config')->get('app.key');

        if ($appKey === '') {
            throw new \RuntimeException('Fusion signs component state with the application key: set APP_KEY.');
        }

        return hash_hmac('sha256', 'nitro-fusion:state', $appKey);
    }

    /** An URL under Fusion's endpoint (config fusion.endpoint). */
    public function endpoint(string $path = ''): string
    {
        return rtrim((string) $this->config('endpoint', '/_fusion'), '/').($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    // ------------------------------------------------------------ components

    /** What Fusion knows about a component, by name or class. */
    public function meta(string $component): ComponentMeta
    {
        $class = class_exists($component) ? ltrim($component, '\\') : $this->registry->class($component);

        if ($class === null) {
            throw new InvalidArgumentException("Unknown Fusion component [{$component}].");
        }

        if (isset($this->metas[$class])) {
            return $this->metas[$class];
        }

        $name = $this->registry->name($class);

        return $this->metas[$class] = ComponentMeta::read($class, $name, Naming::view($name, (string) $this->config('view_prefix', 'fusion')));
    }

    /** Whether a component with this name exists. */
    public function has(string $name): bool
    {
        return $this->registry->class($name) !== null;
    }

    /**
     * A component placed in a view: <fusion:name /> in a page or layout, or a child in another
     * component's view (keyed as the browser keys it: ChildTag::key()).
     */
    public function tag(string $name, array $props, mixed $key, int $number, array $slots = []): HtmlString
    {
        $parent = $this->renderer->current();

        /** <fusion:dynamic-component :is="$name">: the component :is names (none for ""); its key has the name. */
        $dynamic = $name === 'dynamic-component';

        if ($dynamic) {
            $name = (string) ($props['is'] ?? throw new InvalidArgumentException('<fusion:dynamic-component> needs :is, the name of the component to render'));
            unset($props['is']);

            if ($name === '') {
                return new HtmlString('');
            }
        }

        if ($parent === null) {
            return new HtmlString($this->mount($name, $props, $slots));
        }

        $key = ($key !== null ? (string) $key : ChildTag::key($number, $this->renderer->loopIndexes())).($dynamic ? ':'.$name : '');

        return new HtmlString($this->mountChild($name, $props, $parent, $key, $slots));
    }

    /**
     * <fusion:name ...> with content: the content renders (here, in this view) into output buffers,
     * one per slot, until </fusion:name> renders the component with them.
     */
    public function startTag(string $name, array $props, mixed $key, int $number): string
    {
        /** Keyed now: the loops around the tag, not those its content may have. */
        $key = $this->renderer->current() !== null && $key === null ? ChildTag::key($number, $this->renderer->loopIndexes()) : $key;
        $this->tags[] = ['name' => $name, 'props' => $props, 'key' => $key, 'number' => $number, 'slots' => [], 'slot' => null];
        ob_start();

        return '';
    }

    /** <fusion:slot name="footer"> inside a tag with content. */
    public function startSlot(string $name): string
    {
        $top = array_key_last($this->tags);

        if ($top === null || $this->tags[$top]['slot'] !== null) {
            throw new InvalidArgumentException("<fusion:slot name=\"{$name}\"> belongs directly inside a <fusion:...> tag");
        }

        $this->tags[$top]['slot'] = $name;
        ob_start();

        return '';
    }

    public function endSlot(): string
    {
        $top = array_key_last($this->tags);

        if ($top === null || $this->tags[$top]['slot'] === null) {
            throw new InvalidArgumentException('</fusion:slot> closes no <fusion:slot>');
        }

        $this->tags[$top]['slots'][$this->tags[$top]['slot']] = (string) ob_get_clean();
        $this->tags[$top]['slot'] = null;

        return '';
    }

    /** </fusion:name>: the component, with what was between its tags as its default slot. */
    public function endTag(string $name): HtmlString
    {
        $tag = array_pop($this->tags);

        if ($tag === null || $tag['name'] !== $name || $tag['slot'] !== null) {
            throw new InvalidArgumentException("</fusion:{$name}> closes no <fusion:{$name}>");
        }

        $slots = ['default' => (string) ob_get_clean(), ...$tag['slots']];

        return $this->tag($tag['name'], $tag['props'], $tag['key'], $tag['number'], $slots);
    }

    /**
     * The @portal('modals') directive, Viewi's <Portal name="modals">: its marks, and where the content
     * teleported to it goes once the response is rendered (fillPortals()).
     */
    public function portal(string $name): string
    {
        $scope = $this->portals[$name] ??= ['defined' => false, 'contents' => []];

        if ($scope['defined']) {
            throw new InvalidArgumentException("Portal '{$name}' is already defined. Must be only one portal with such name.");
        }

        $this->portals[$name]['defined'] = true;
        $name = e($name);

        return "<i id=\"portal_{$name}\" style=\"display: none !important;\"></i>"
            ."PORTAL_{$name}"
            ."<i id=\"portal_{$name}_end\" style=\"display: none !important;\"></i>";
    }

    /** The @teleport('modals') directive, Viewi's <Portal to="modals">: its content renders into a buffer, for the portal. */
    public function startTeleport(string $name): string
    {
        $this->teleporting[] = $name;
        ob_start();

        return '';
    }

    public function endTeleport(): string
    {
        $name = array_pop($this->teleporting) ?? throw new InvalidArgumentException('@endteleport closes no @teleport');
        $this->portals[$name] ??= ['defined' => false, 'contents' => []];
        $this->portals[$name]['contents'][] = [
            'order' => $this->renderer->order(),
            'seq' => count($this->portals[$name]['contents']),
            'html' => (string) ob_get_clean(),
        ];

        return '';
    }

    /**
     * Viewi's portals post action: each portal's placeholder becomes the content teleported to it.
     * Fusion: in the order the browser hydrates it (a component's children hydrate after it, the
     * server renders them inside it), then as it was rendered. The portals are done with after.
     */
    public function fillPortals(string $body): string
    {
        foreach ($this->portals as $name => $scope) {
            $contents = $scope['contents'];
            usort($contents, fn (array $a, array $b) => [$a['order'], $a['seq']] <=> [$b['order'], $b['seq']]);
            $name = e($name);
            $body = str_replace("PORTAL_{$name}<i id=\"portal_{$name}_end\"", implode('', array_column($contents, 'html'))."<i id=\"portal_{$name}_end\"", $body);
        }

        $this->portals = [];

        return $body;
    }

    /**
     * Render a component that is not inside another (in a page, a layout, @fusion).
     *
     * @param  array<string, string>  $slots  name => its HTML
     */
    public function mount(string $name, array $props = [], array $slots = []): string
    {
        $meta = $this->meta($name);
        $component = $this->create($meta, 'f'.Str::lower(Str::random(10)), $props);
        $component->fusion()->slots = $slots;

        return $this->renderer->render($component, $meta, $this->key(), $this->pageMemo());
    }

    /** @param  array<string, string>  $slots */
    private function mountChild(string $name, array $props, Component $parent, string $key, array $slots = []): string
    {
        $meta = $this->meta($name);
        $component = $this->create($meta, $parent->getId().'.'.$key, $props);
        $component->fusion()->slots = $slots;

        return $this->renderer->render($component, $meta, $this->key(), $this->pageMemo());
    }

    /**
     * A new instance: mount() receives the props as named arguments when the component has one;
     * otherwise the props set its public properties (as the browser does for such components).
     */
    private function create(ComponentMeta $meta, string $id, array $props): Component
    {
        /** @var Component $component */
        $component = $this->app->make($meta->class);
        $component->fusion()->id = $id;
        $component->fusion()->name = $meta->name;
        Hooks::call($component, 'boot');

        /** fusion:model on its tag: the parent's value, for its #[Modelable] property (once mounted). */
        $model = array_key_exists('$model', $props) ? [$props['$model']] : null;
        unset($props['$model']);

        if ($model !== null && $meta->modelable === null) {
            throw new InvalidArgumentException("fusion:model on <fusion:{$meta->name}>: the component has no #[Modelable] property.");
        }

        if ($meta->hasMount) {
            $this->applyUrl($component, $meta);
            $this->app->call([$component, 'mount'], $props);
        } else {
            foreach ($props as $prop => $value) {
                if ($meta->property($prop) === null) {
                    throw new InvalidArgumentException("Fusion component [{$meta->name}] has no public property \${$prop}.");
                }

                $component->{$prop} = $value;
            }

            $this->applyUrl($component, $meta);
        }

        if ($model !== null) {
            $component->{$meta->modelable} = $model[0];
        }

        return $component;
    }

    /**
     * #[Url] properties from the request's query string, when the component is created (before
     * mount(), so mount() sees them). A value that does not fit the property's type is ignored.
     */
    private function applyUrl(Component $component, ComponentMeta $meta): void
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        if (! $request instanceof Request) {
            return;
        }

        $query = $request->query->all();

        foreach ($meta->properties as $name => $property) {
            $key = $property->url === null ? null : ($property->url->as ?? $name);

            if ($key === null || ! array_key_exists($key, $query)) {
                continue;
            }

            [$fits, $value] = $property->fromQuery($query[$key]);

            if ($fits) {
                $component->{$name} = $value;
            }
        }
    }

    /**
     * A routed page (Route::fusion()): mount() with the route's parameters, then the component
     * inside its layout; or, for a fusion:navigate visit, only what the browser needs to render
     * the page itself (pageData()).
     */
    public function page(Component $component, array $parameters): Response
    {
        $meta = $this->meta($component::class);
        $component->fusion()->id = 'f'.Str::lower(Str::random(10));
        $component->fusion()->name = $meta->name;
        Hooks::call($component, 'boot');
        $this->applyUrl($component, $meta);

        if ($meta->hasMount) {
            /** The route's parameters, already bound and resolved by the router, in mount()'s order. */
            [$component, 'mount'](...array_values($parameters));
        }

        $layout = $meta->layout->view ?? (string) $this->config('layout', 'components.layouts.app');
        $layoutData = [...($meta->layout->data ?? []), 'title' => $meta->title];

        /** The same URL answers with HTML or with page data: caches must keep them apart. */
        if ($this->app->make('request')->headers->has(self::NAVIGATE_HEADER)) {
            return $this->pageData($component, $meta, $layout, $layoutData)->setVary(self::NAVIGATE_HEADER, false);
        }

        $html = $this->renderer->render($component, $meta, $this->key(), $this->pageMemo(), ['fusion:page' => $this->layoutKey($layout, $layoutData)]);

        $page = $this->app->make('view')->make($layout, [...$layoutData, 'slot' => new HtmlString($html)])->render();

        return response($this->fillPortals($page))->setVary(self::NAVIGATE_HEADER, false);
    }

    /**
     * A page for the browser to render: its signed state, the title its layout gives it, and the
     * layout it belongs in (a different one is a full page load). The browser has the compiled
     * component; no HTML is rendered here, except to create the children that have mount(): those
     * can only be created on the server, with the props the server's render passes them.
     */
    private function pageData(Component $component, ComponentMeta $meta, string $layout, array $layoutData): JsonResponse
    {
        $key = $this->key();
        $memo = $this->pageMemo();

        $children = $this->hasServerChildren($meta)
            ? $this->renderer->collectChildren(fn () => $this->renderer->render($component, $meta, $key, $memo))
            : [];

        return new JsonResponse([
            'fusion' => 1,
            'snapshot' => json_decode(Snapshot::capture($component, $meta, $memo)->toJson($key), true),
            'children' => (object) $children,
            'title' => $this->layoutTitle($layout, $layoutData),
            'layout' => $this->layoutKey($layout, $layoutData),
        ], 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Pages share the page around them when they have the same layout, with the same data. */
    private function layoutKey(string $layout, array $layoutData): string
    {
        unset($layoutData['title']);

        return $layoutData === [] ? $layout : $layout.'#'.substr(md5(serialize($layoutData)), 0, 12);
    }

    /** The document title as the layout writes it (the layout owns its format). */
    private function layoutTitle(string $layout, array $layoutData): ?string
    {
        $html = $this->app->make('view')->make($layout, [...$layoutData, 'slot' => new HtmlString('')])->render();

        return preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m)
            ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;
    }

    /**
     * Whether the component's view places (itself or through the children it places) a child that
     * has mount(): only the server can create those.
     *
     * @param  array<string, true>  $seen  classes already looked at (views can place each other)
     */
    private function hasServerChildren(ComponentMeta $meta, array &$seen = []): bool
    {
        if (isset($this->serverChildren[$meta->class])) {
            return $this->serverChildren[$meta->class];
        }

        $seen[$meta->class] = true;
        $source = (string) file_get_contents($this->app->make('view')->getFinder()->find($meta->view));
        $found = false;

        preg_match_all('/<fusion:([\w.\-]+)/', $source, $tags);

        foreach (array_unique($tags[1]) as $name) {
            /** It may name any component, one with mount() too. */
            if ($name === 'dynamic-component') {
                $found = true;

                break;
            }

            if (! $this->has($name)) {
                continue;
            }

            $child = $this->meta($name);

            if ($child->hasMount || (! isset($seen[$child->class]) && $this->hasServerChildren($child, $seen))) {
                $found = true;

                break;
            }
        }

        return $this->serverChildren[$meta->class] = $found;
    }

    /** The page the components were rendered for: their #[Server] calls re-check its middleware. */
    private function pageMemo(): array
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        if (! $request instanceof Request || $request->route() === null) {
            return [];
        }

        return ['route' => ['method' => $request->getMethod(), 'uri' => $request->getRequestUri()]];
    }

    // ------------------------------------------------------------ assets

    /** @fusionScripts: the runtime and the application's compiled components. */
    public function scripts(): HtmlString
    {
        $token = $this->app->bound('session.store') && $this->app->make('request')->hasSession() ? csrf_token() : '';
        $runtime = e($this->endpoint('fusion.js')).'?v='.e($this->runtimeVersion());
        $tag = $this->app->make(Build\Bundle::class)->tag();
        $bundle = e($this->endpoint('app.js')).'?v='.e($tag);

        $progress = $this->progress();
        $progress = $progress === null ? '' : ' data-progress="'.e(json_encode($progress, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)).'"';

        /** The #[LazyLoad] chunks of the components on this page, loaded with the bundle. */
        $chunks = array_map(
            fn (string $group) => "\n".'<script src="'.e($this->endpoint('app/'.$group.'.js')).'?v='.e($tag).'" defer></script>',
            $this->renderer->chunks(),
        );

        $globals = json_encode($this->globals(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return new HtmlString(
            '<script type="application/json" data-fusion-globals>'.$globals.'</script>'."\n"
            .'<script src="'.$runtime.'" data-endpoint="'.e($this->endpoint()).'" data-bundle="'.e($tag).'" data-csrf="'.e($token).'"'.$progress.' defer></script>'."\n"
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
        $uses = $this->app->make(Build\Bundle::class)->uses();
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
            $config[$key] = Support\Wire::encode($this->app->make('config')->get($key));
        }

        return [
            'url' => $urls->to('/'),
            'asset' => rtrim($urls->asset('/'), '/'),
            'locale' => $this->app->make('translator')->getLocale(),
            'routes' => (object) $routes,
            'lang' => (object) $lang,
            'config' => (object) $config,
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
            'delay' => (int) ($config['delay'] ?? 150),
            'color' => (string) ($config['color'] ?? '#29d'),
            'height' => (string) ($config['height'] ?? '3px'),
            'position' => ($config['position'] ?? 'top') === 'bottom' ? 'bottom' : 'top',
            'glow' => filter_var($config['glow'] ?? true, FILTER_VALIDATE_BOOL),
            'glowWidth' => (string) ($config['glow_width'] ?? '100px'),
            'spinner' => filter_var($config['spinner'] ?? false, FILTER_VALIDATE_BOOL),
            'spinnerSize' => (string) ($config['spinner_size'] ?? '18px'),
            'zIndex' => (int) ($config['z_index'] ?? 1031),
            'minimum' => (float) ($config['minimum'] ?? 0.08),
            'easing' => (string) ($config['easing'] ?? 'ease'),
            'speed' => (int) ($config['speed'] ?? 200),
            'trickle' => filter_var($config['trickle'] ?? true, FILTER_VALIDATE_BOOL),
            'trickleSpeed' => (int) ($config['trickle_speed'] ?? 200),
        ];
    }

    public function runtimePath(): string
    {
        return dirname(__DIR__).'/dist/fusion.js';
    }

    public function runtimeVersion(): string
    {
        return substr((string) @md5_file($this->runtimePath()), 0, 12);
    }
}
