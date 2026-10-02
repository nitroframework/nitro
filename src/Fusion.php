<?php

namespace Nitro\Fusion;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use Nitro\Fusion\Browser\Browser;
use Nitro\Fusion\Browser\BrowserOnlyException;
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

    /** Sent with fusion:navigate visits: the version of the components the browser runs. */
    public const VERSION_HEADER = 'X-Fusion-Version';

    /** Sent when a page reloads its properties (deferred ones, $this->reload()): the id it has in the browser. */
    public const PARTIAL_HEADER = 'X-Fusion-Partial';

    /** With a partial reload: the properties it wants, comma-separated (all of them when absent). */
    public const ONLY_HEADER = 'X-Fusion-Only';

    /** Sent by the browser: the once shared data it has (Fusion::once()), comma-separated. */
    public const ONCE_HEADER = 'X-Fusion-Once';

    /** Where Fusion::flash()'s data waits in the session for the response that delivers it. */
    public const FLASH_KEY = 'fusion.flash';

    /** @var array<class-string, ComponentMeta> */
    private array $metas = [];

    /** @var array<class-string, bool> */
    private array $serverChildren = [];

    /**
     * <fusion:...> tags with content being rendered, innermost last: the slots they collect.
     *
     * @var list<array{name: string, props: array, key: mixed, number: int, hydrate: ?string, slots: array<string, string>, slot: ?string}>
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

    /** @var array<string, mixed> Fusion::share()'s data, closures not yet called */
    private array $shared = [];

    private ?Build\Obfuscator $names = null;

    private bool $namesResolved = false;

    /** Whether the layout is being rendered for its title only (layoutTitle()). */
    private bool $renderingTitle = false;

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

    /**
     * The browser's APIs (clipboard, storage, canvas, sockets), in a component's browser methods:
     * compiled to JavaScript. On the server it throws.
     */
    public function browser(): Browser
    {
        throw BrowserOnlyException::make();
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    // ------------------------------------------------------------ obfuscated builds

    /** An obfuscated build's names (fusion.build.obfuscate), when the bundle the browser gets is one; else null. */
    public function names(): ?Build\Obfuscator
    {
        if (! $this->namesResolved) {
            $this->names = $this->app->make(Build\Bundle::class)->obfuscator();
            $this->namesResolved = true;
        }

        return $this->names;
    }

    /** A component's or page's name in the browser. */
    public function browserName(string $name): string
    {
        return $this->names()?->component($name) ?? $name;
    }

    /** The component a name from the browser stands for. */
    public function componentName(string $browserName): string
    {
        return $this->names()?->componentName($browserName) ?? $browserName;
    }

    /**
     * The method a name from the browser stands for, among the component's #[Server] methods.
     *
     * @param  list<string>  $methods
     */
    public function methodName(string $browserName, array $methods): string
    {
        return $this->names()?->methodName($browserName, $methods) ?? $browserName;
    }

    /**
     * A component's rendered HTML as an obfuscated build's page has it: handlers' text left out
     * (fusion:click="save" becomes fusion:click=""; the compiled code is what runs), and
     * fusion:target naming the component's methods by their aliases (a property's, as an upload's,
     * too), and fusion:model's path starting with its property's alias. Unchanged otherwise.
     *
     * @param  list<string>  $methods  the component's methods
     * @param  list<string>  $properties  the component's properties
     */
    public function obscure(string $html, array $methods = [], array $properties = []): string
    {
        $names = $this->names();

        if ($names === null || ! str_contains($html, 'fusion:')) {
            return $html;
        }

        $handlers = implode('|', array_map(fn (string $name) => preg_quote($name, '/'), [...Compiler\Globals::EVENTS, ...array_keys(Compiler\Globals::HOOKS)]));

        /** Each start tag (quoted values may hold ">"), then its fusion attributes. */
        $target = fn (string $name) => match (true) {
            in_array(trim($name), $methods, true) => $names->method(trim($name)),
            in_array(trim($name), $properties, true) => $names->property(trim($name)),
            default => trim($name),
        };
        $path = fn (string $path) => in_array(explode('.', $path, 2)[0], $properties, true) ? $names->path($path) : $path;

        return (string) preg_replace_callback('/<[a-zA-Z][^\s\/>]*(?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*\s*\/?>/', function (array $tag) use ($handlers, $target, $path) {
            if (! str_contains($tag[0], 'fusion:')) {
                return $tag[0];
            }

            $tag = (string) preg_replace('/(\sfusion:(?:'.$handlers.')(?:\.[\w\-.]+)?)\s*=\s*(?:"[^"]*"|\'[^\']*\')/', '$1=""', $tag[0]);
            $tag = (string) preg_replace_callback('/(\sfusion:model(?:\.[\w\-.]+)?\s*=\s*)(["\'])(.*?)\2/', fn (array $m) => $m[1].$m[2].$path($m[3]).$m[2], $tag);

            return (string) preg_replace_callback('/(\sfusion:target\s*=\s*)(["\'])(.*?)\2/', fn (array $m) => $m[1].$m[2].implode(',', array_map($target, explode(',', $m[3]))).$m[2], $tag);
        }, $html);
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

        return $this->metas[$class] = ComponentMeta::read($class, $name, $this->registry->view($name), Naming::isPage($name));
    }

    /** Whether a component with this name exists. */
    public function has(string $name): bool
    {
        return $this->registry->class($name) !== null;
    }

    /**
     * A component placed in a view: <fusion:name /> in a page or layout, or a child in another
     * component's view (keyed as the browser keys it: ChildTag::key()). $hydrate: an island's start
     * (fusion:hydrate, fusion:static), for a component that is not a child.
     */
    public function tag(string $name, array $props, mixed $key, int $number, array $slots = [], ?string $hydrate = null): HtmlString
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
            return new HtmlString($this->mount($name, $props, $slots, $hydrate));
        }

        if ($hydrate !== null) {
            throw new InvalidArgumentException("fusion:hydrate and fusion:static are for islands, components placed in Blade views: <fusion:{$name}> is a child of [{$parent->fusion()->name}], and starts with it.");
        }

        $key = ($key !== null ? (string) $key : ChildTag::key($number, $this->renderer->loopIndexes())).($dynamic ? ':'.$name : '');

        return new HtmlString($this->mountChild($name, $props, $parent, $key, $slots));
    }

    /**
     * <fusion:name ...> with content: the content renders (here, in this view) into output buffers,
     * one per slot, until </fusion:name> renders the component with them.
     */
    public function startTag(string $name, array $props, mixed $key, int $number, ?string $hydrate = null): string
    {
        /** Keyed now: the loops around the tag, not those its content may have. */
        $key = $this->renderer->current() !== null && $key === null ? ChildTag::key($number, $this->renderer->loopIndexes()) : $key;
        $this->tags[] = ['name' => $name, 'props' => $props, 'key' => $key, 'number' => $number, 'hydrate' => $hydrate, 'slots' => [], 'slot' => null];
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

        return $this->tag($tag['name'], $tag['props'], $tag['key'], $tag['number'], $slots, $tag['hydrate']);
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
     * An island that starts later ($hydrate: fusion:hydrate) is marked for the browser, and the
     * chunks of its components are not loaded with the page; a static one (fusion:static) is its
     * HTML only, without state for the browser.
     *
     * @param  array<string, string>  $slots  name => its HTML
     */
    public function mount(string $name, array $props = [], array $slots = [], ?string $hydrate = null): string
    {
        $render = function () use ($name, $props, $slots, $hydrate): string {
            $meta = $this->placeable($name);
            $component = $this->create($meta, 'f'.Str::lower(Str::random(10)), $props);
            $component->fusion()->slots = $slots;
            $attributes = $hydrate === null || $hydrate === 'static' ? [] : ['fusion:hydrate' => $hydrate];
            /** No parent renders its slots in the browser: their HTML goes with its state. */
            $memo = $this->pageMemo() + (array_filter($slots, fn (string $html) => trim($html) !== '') === [] ? [] : ['slots' => $slots]);

            return $this->renderer->render($component, $meta, $this->key(), $memo, $attributes);
        };

        if ($hydrate === null) {
            return $render();
        }

        $html = $this->renderer->later($render);

        return $hydrate === 'static' ? (string) preg_replace('/\sfusion:(?:snapshot|id|name)="[^"]*"/', '', $html) : $html;
    }

    /** @param  array<string, string>  $slots */
    private function mountChild(string $name, array $props, Component $parent, string $key, array $slots = []): string
    {
        $meta = $this->placeable($name);
        $component = $this->create($meta, $parent->getId().'.'.$key, $props);
        $component->fusion()->slots = $slots;

        return $this->renderer->render($component, $meta, $this->key(), $this->pageMemo());
    }

    /** A component placed in a view (<fusion:...>, @fusion, a dynamic component): not a page. */
    private function placeable(string $name): ComponentMeta
    {
        $meta = $this->meta($name);

        if ($meta->page) {
            throw new InvalidArgumentException("[{$meta->name}] is a page: pages are routed (Route::fusion()) or rendered by controllers (Fusion::render()), not placed in views. Place a component instead.");
        }

        return $meta;
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
        $component->fusion()->id = $this->pageId();
        $component->fusion()->name = $meta->name;
        Hooks::call($component, 'boot');
        $this->applyUrl($component, $meta);

        if ($meta->hasMount) {
            /** The route's parameters, already bound and resolved by the router, in mount()'s order. */
            [$component, 'mount'](...array_values($parameters));
        }

        return $this->respond($component, $meta);
    }

    /**
     * A page from a controller, as Inertia::render() returns one: the component created with the
     * props (mount()'s arguments by name, or its public properties when it has no mount()), then
     * answered as a Route::fusion() page is, fusion:navigate visits included. A Fusion::defer()
     * prop sets its public property after the page is shown.
     *
     * @param  string  $component  the component's class or name
     * @param  array<string, mixed>  $props
     */
    public function render(string $component, array $props = []): Response
    {
        $meta = $this->meta($component);

        if (! $meta->page) {
            throw new InvalidArgumentException("Fusion::render() renders pages: [{$meta->name}] is a component. Pages live in the pages namespace (fusion.pages); components are placed in views with <fusion:...>.");
        }

        $deferred = array_filter($props, fn (mixed $value) => $value instanceof Deferred);
        $props = array_diff_key($props, $deferred);

        /**
         * A closure prop (or a merge prop's closure) is called when the page needs it: a partial
         * reload skips those it does not want (pages without mount()).
         */
        $merges = [];

        foreach ($props as $prop => $value) {
            if (! $value instanceof Closure && ! $value instanceof Merge) {
                continue;
            }

            if (! $meta->hasMount && ! $this->wants((string) $prop)) {
                unset($props[$prop]);

                continue;
            }

            if ($value instanceof Merge) {
                $merges[$prop] = $value->rule();
            }

            $props[$prop] = $value instanceof Merge ? $value->resolve($this->app->call(...)) : $this->app->call($value);
        }

        $page = $this->create($meta, $this->pageId(), $props);

        /** On a partial reload, the merge props it reloads go with their rule (Snapshot::only()). */
        if ($this->partial() !== null) {
            $page->fusion()->merges = $merges;
        }

        foreach ($deferred as $property => $value) {
            $this->deferProperty($page, (string) $property, $value->value);
        }

        return $this->respond($page, $meta);
    }

    /**
     * A prop for Fusion::render() merged into the page's value on a partial reload, instead of
     * replacing it, as Inertia::merge(): appended by default (->prepend(), ->matchOn('id')).
     */
    public function merge(mixed $value): Merge
    {
        return new Merge($value);
    }

    /** A merge prop merged key by key, all the way down, as Inertia::deepMerge(). */
    public function deepMerge(mixed $value): Merge
    {
        return (new Merge($value))->deep();
    }

    /**
     * A paginator for a list that loads as it scrolls, as Inertia::scroll(): the page holds
     * ['data' => items, 'scroll' => where it is], and $loadMore('prop') reloads the next page
     * into it.
     */
    public function scroll(mixed $paginator): Merge
    {
        return (new Merge($paginator))->asScroll();
    }

    /**
     * A prop for Fusion::render() loaded after the page is shown, as Inertia::defer(): the browser
     * asks for it right away, and @deferred / @placeholder show something meanwhile.
     */
    public function defer(Closure $value): Deferred
    {
        return new Deferred($value);
    }

    /**
     * A page property loaded after the page is shown ($this->defer(), Fusion::defer()): set when
     * a partial reload wants it; left as it is otherwise, and named in the snapshot for the
     * browser to ask for (a partial reload that does not want it leaves it alone).
     *
     * @internal
     */
    public function deferProperty(Component $page, string $property, Closure $value): void
    {
        $meta = $this->meta($page::class);

        if (! $meta->page) {
            throw new InvalidArgumentException("Only pages defer properties: [{$meta->name}] is a component.");
        }

        if ($meta->property($property) === null) {
            throw new InvalidArgumentException("Fusion page [{$meta->name}] has no public property \${$property} to defer.");
        }

        if ($this->partial() === null) {
            $page->fusion()->deferred[] = $property;
        } elseif ($this->wants($property)) {
            $page->{$property} = $this->app->call($value);
        }
    }

    /**
     * A page reloading its properties in the browser (X-Fusion-Partial): the ones it wants, an
     * empty list for all of them. Null for any other request.
     *
     * @return list<string>|null
     */
    public function partial(): ?array
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        if (! $request instanceof Request || ! $request->headers->has(self::NAVIGATE_HEADER) || ! $request->headers->has(self::PARTIAL_HEADER)) {
            return null;
        }

        $only = array_map('trim', explode(',', (string) $request->headers->get(self::ONLY_HEADER, '')));

        return array_values(array_filter($only, fn (string $name) => preg_match('/^\w+$/', $name) === 1));
    }

    /** Whether the request needs the page property: any request but a partial reload of others. */
    public function wants(string $property): bool
    {
        $only = $this->partial();

        return $only === null || $only === [] || in_array($property, $only, true)
            || ($this->names() !== null && in_array($this->names()->property($property), $only, true));
    }

    /**
     * A partial reload's properties by the component's own names (the browser names them by
     * an obfuscated build's aliases).
     *
     * @param  list<string>  $only
     * @return list<string>
     */
    private function partialProperties(array $only, ComponentMeta $meta): array
    {
        $names = $this->names();

        return $names === null ? $only : array_map(fn (string $name) => $names->propertyName($name, array_keys($meta->properties)) ?? $name, $only);
    }

    /** A new page's id; on a partial reload, the one it has in the browser. */
    private function pageId(): string
    {
        $id = $this->partial() !== null ? (string) $this->app->make('request')->headers->get(self::PARTIAL_HEADER) : '';

        return preg_match('/^f[a-z0-9]{10}$/', $id) === 1 ? $id : 'f'.Str::lower(Str::random(10));
    }

    /** The page's answer: HTML in its layout, or the page data for a fusion:navigate visit. */
    private function respond(Component $component, ComponentMeta $meta): Response
    {
        $layout = $meta->layout->view ?? (string) $this->config('layout', 'components.layouts.app');
        $layoutData = [...($meta->layout->data ?? []), 'title' => $meta->title];

        /** The same URL answers with HTML or with page data: caches must keep them apart. */
        $request = $this->app->make('request');

        if ($request->headers->has(self::NAVIGATE_HEADER)) {
            /** The browser runs another build's components (a deploy since it loaded): load the page in full. */
            $version = $request->headers->get(self::VERSION_HEADER);

            if ($version !== null && $version !== $this->app->make(Build\Bundle::class)->tag()) {
                return (new HttpResponse('', 409, ['X-Fusion-Location' => $request->fullUrl()]))->setVary(self::NAVIGATE_HEADER, false);
            }

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

        $snapshot = Snapshot::capture($component, $meta, $memo);
        $only = $this->partial();

        return new JsonResponse([
            'fusion' => 1,
            'snapshot' => json_decode($snapshot->toJson($key, $this->browserName($meta->name), $this->names()), true),
            /** A partial reload of some properties: those alone, signed, for the browser to lay over its own state. */
            'partial' => $only === null || $only === [] ? null : json_decode($snapshot->only($this->partialProperties($only, $meta), $component->fusion()->merges)->toJson($key, $this->browserName($meta->name), $this->names()), true),
            'children' => (object) $children,
            'title' => $this->layoutTitle($layout, $layoutData),
            'layout' => $this->layoutKey($layout, $layoutData),
            'shared' => $this->sharedForBrowser(),
            'once' => (object) $this->onceForBrowser(),
            'flash' => $this->flashForBrowser(),
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
        /** For its title only: @fusionScripts writes nothing (no shared data resolved, no globals built). */
        $this->renderingTitle = true;

        try {
            $html = $this->app->make('view')->make($layout, [...$layoutData, 'slot' => new HtmlString('')])->render();
        } finally {
            $this->renderingTitle = false;
        }

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
        if ($this->renderingTitle) {
            return new HtmlString('');
        }

        $token = $this->app->bound('session.store') && $this->app->make('request')->hasSession() ? csrf_token() : '';
        $built = $this->app->make(Build\Bundle::class);
        $tag = $built->tag();
        $urls = $this->app->make('url');

        /** fusion:build's static files when it has them for these components (served without PHP), else Fusion's routes. */
        $static = fn (?string $file) => $file === null ? null : $urls->asset($file);
        $runtime = e($static($built->staticRuntime()) ?? $this->endpoint('fusion.js').'?v='.$this->runtimeVersion());
        $bundle = e($static($built->staticFile()) ?? $this->endpoint('app.js').'?v='.$tag);
        $chunkUrl = $static($built->staticChunkPattern()) ?? $this->endpoint('app/{id}.js').'?v='.$tag;

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
            .'<script src="'.$runtime.'" data-endpoint="'.e($this->endpoint()).'" data-bundle="'.e($tag).'" data-chunks="'.e($chunkUrl).'" data-csrf="'.e($token).'"'.$progress.' defer></script>'."\n"
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
            'shared' => $this->sharedForBrowser(),
            'once' => (object) $this->onceForBrowser(),
            'flash' => $this->flashForBrowser(),
        ];
    }

    // ------------------------------------------------------------ shared data

    /**
     * Data every component can read with $this->shared(), on the server and in the browser, as
     * Inertia's shared props: the signed-in user, flash messages. A value can be a closure,
     * called when the data is read. Keys use dot notation. The data is sent to the browser
     * with every page, fusion:navigate visit and #[Server] call: nothing secret.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function share(string|array $key, mixed $value = null): void
    {
        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            Arr::set($this->shared, (string) $name, $item);
        }
    }

    /** The shared data, or one value of it (dot notation), with the closures called. */
    public function shared(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->resolveShared($this->shared);

        return $key === null ? $data : data_get($data, $key, $default);
    }

    /**
     * Shared data sent once (Fusion::once()), as Inertia's once props: the browser keeps it, and
     * a response neither calls the closure for the browser nor sends it while the browser has it.
     * Reading it on the server ($this->shared()) calls the closure as usual.
     */
    public function once(Closure $value): Once
    {
        return new Once($value);
    }

    /** Share data sent once: Fusion::share($key, Fusion::once($value)). */
    public function shareOnce(string $key, Closure $value): Once
    {
        $once = new Once($value);
        $this->share($key, $once);

        return $once;
    }

    /**
     * The shared data as the browser reads it (B.shared()), without the once data the browser
     * says it has (X-Fusion-Once), unless it is fresh.
     */
    public function sharedForBrowser(): mixed
    {
        $held = $this->heldOnce();
        $shared = array_filter($this->shared, fn (mixed $value, string|int $key) => ! ($value instanceof Once && in_array((string) $key, $held, true) && ! $value->isFresh()), ARRAY_FILTER_USE_BOTH);

        return Support\Wire::encode($this->resolveShared($shared));
    }

    /**
     * The once data's keys, and when the browser asks for each again (Unix time in seconds, null
     * for never): what it keeps.
     *
     * @return array<string, int|null>
     */
    public function onceForBrowser(): array
    {
        $once = [];

        foreach ($this->shared as $key => $value) {
            if ($value instanceof Once) {
                $once[(string) $key] = $value->expiresAt();
            }
        }

        return $once;
    }

    /** @return list<string> the once data the browser has (X-Fusion-Once) */
    private function heldOnce(): array
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;
        $header = $request instanceof Request ? (string) $request->headers->get(self::ONCE_HEADER, '') : '';

        return array_values(array_filter(array_map('trim', explode(',', $header)), fn (string $key) => $key !== ''));
    }

    // ------------------------------------------------------------ flash data

    /**
     * Data for the next response only, as Inertia::flash(): a toast after a save. It goes with the
     * next page, visit or #[Server] answer (this request's, or the next one's after a redirect),
     * then it is gone, and the browser keeps it out of history. Read with $this->flashed().
     *
     * @param  string|array<string, mixed>  $key
     */
    public function flash(string|array $key, mixed $value = null): void
    {
        $session = $this->session() ?? throw new \RuntimeException('Fusion::flash() keeps its data in the session: the request has none (the web middleware starts it).');
        $data = $this->flashData();

        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            Arr::set($data, (string) $name, $item);
        }

        $session->flash(self::FLASH_KEY, $data);
    }

    /** The flash data, or one value of it (dot notation). */
    public function flashed(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->flashData() : data_get($this->flashData(), $key, $default);
    }

    /**
     * The flash data for the browser (B.flashed()). Delivered, it is this request's only (the
     * session's now()): read again within it (the layout rendered for the title), gone after.
     */
    public function flashForBrowser(): mixed
    {
        $data = $this->flashData();

        $session = $this->session();

        if ($data !== [] && $session !== null) {
            /** The session's now(): kept for this request, dropped when it ends. */
            $session->put(self::FLASH_KEY, $data);
            $session->put('_flash.old', array_values(array_unique([...(array) $session->get('_flash.old', []), self::FLASH_KEY])));
        }

        return Support\Wire::encode($this->resolveShared($data));
    }

    /**
     * The flash data waiting in the session: the last request's, and this one's. Only there, not
     * on this object: a Fusion kept past its request (a facade, Octane) carries none of it over.
     *
     * @return array<string, mixed>
     */
    private function flashData(): array
    {
        return (array) ($this->session()?->get(self::FLASH_KEY, []) ?? []);
    }

    private function session(): ?\Illuminate\Contracts\Session\Session
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        return $request instanceof Request && $request->hasSession() ? $request->session() : null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function resolveShared(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof Once) {
                $value = $value->value;
            }

            if ($value instanceof Closure) {
                $value = $this->app->call($value);
            }

            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            } elseif ($value instanceof JsonSerializable) {
                $value = $value->jsonSerialize();
            }

            $values[$key] = is_array($value) ? $this->resolveShared($value) : $value;
        }

        return $values;
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

    public function runtimePath(): string
    {
        return dirname(__DIR__).'/dist/fusion.js';
    }

    public function runtimeVersion(): string
    {
        return substr((string) @md5_file($this->runtimePath()), 0, 12);
    }
}
