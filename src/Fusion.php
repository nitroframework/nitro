<?php

namespace Nitro\Fusion;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nitro\Fusion\Browser\Browser;
use Nitro\Fusion\Browser\BrowserOnlyException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\Template\ChildTag;
use Nitro\Fusion\Http\PageResponses;
use Nitro\Fusion\Http\Scripts;
use Nitro\Fusion\Rendering\PageHead;
use Nitro\Fusion\Rendering\Portals;
use Nitro\Fusion\Rendering\Renderer;
use Nitro\Fusion\Rendering\Snapshot;
use Nitro\Fusion\Rendering\Tags;
use Nitro\Fusion\Rendering\UnplaceableComponent;
use Nitro\Fusion\State\SharedData;
use Nitro\Fusion\Support\Hooks;
use Nitro\Fusion\Support\Naming;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fusion in an application: its configuration, its components, and rendering them on the server.
 * Everything else asks this class (the service provider binds it once per request: scoped).
 *
 * The core is here: components (meta(), mounting them), the keys that sign their state, an
 * obfuscated build's names. The rest is in parts, each behind the same methods here:
 *   Rendering\Tags          <fusion:...> tags with content and their slots
 *   Rendering\Portals       @portal and @teleport
 *   Rendering\PageHead      the page's <head> (@fusionHead) and the layout's title
 *   Http\PageResponses      a routed page's answer, visits, partial reloads, merge and deferred props
 *   Http\Scripts            @fusionScripts and the browser's globals
 *   State\SharedData        shared, once and flash data
 */
final class Fusion
{
    /** Sent by the browser's fusion:navigate visits: the page answers with data, not HTML. */
    public const NAVIGATE_HEADER = 'X-Fusion-Navigate';

    /** Sent with fusion:navigate visits: the version of the components the browser runs. */
    public const VERSION_HEADER = 'X-Fusion-Version';

    /** Sent when a page reloads its properties (deferred ones, $this->reload()): the id it has in the browser. */
    public const PARTIAL_HEADER = 'X-Fusion-Partial';

    /** Sent by a markup-first visit: the id of the page the browser rendered before its data. */
    public const ID_HEADER = 'X-Fusion-Id';

    /** With a partial reload: the properties it wants, comma-separated (all of them when absent). */
    public const ONLY_HEADER = 'X-Fusion-Only';

    /** Sent by the browser: the once shared data it has (Fusion::once()), comma-separated. */
    public const ONCE_HEADER = 'X-Fusion-Once';

    /** Where Fusion::flash()'s data waits in the session for the response that delivers it. */
    public const FLASH_KEY = 'fusion.flash';

    /** @var array<class-string, ComponentMeta> */
    private array $metas = [];

    private ?Build\Obfuscator $names = null;

    private bool $namesResolved = false;

    /** The page the components being rendered belong to, when it is not this request's (childrenOf()). */
    private ?array $renderingFor = null;

    /** @var array<string, Store> this request's stores, by name: one each, shared by the components that use it */
    private array $stores = [];

    /** @var array<string, array<string, mixed>> the page each store the browser sent was made for (its signed memo) */
    private array $storeMemos = [];

    private readonly Tags $tags;

    private readonly Portals $portals;

    private readonly PageHead $head;

    private readonly SharedData $data;

    private readonly PageResponses $pages;

    private readonly Scripts $scripts;

    public function __construct(
        private readonly Container $app,
        private readonly array $config,
        private readonly Registry $registry,
        private readonly Renderer $renderer,
    ) {
        $this->tags = new Tags($this, $renderer);
        $this->portals = new Portals($renderer);
        $this->head = new PageHead($this, $app, $this->portals);
        $this->data = new SharedData($app);
        $this->pages = new PageResponses($this, $app, $renderer, $this->head, $this->portals, $this->data);
        $this->scripts = new Scripts($this, $app, $renderer, $this->head, $this->data);
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

    /**
     * The key component state is signed with: the application's, mixed with this session's own
     * secret (fusion.state.bind_to_session) and who is logged in, so a snapshot is good in the
     * session it was made for, for the user it was made for: not another visitor's, not after a
     * logout (Auth::logout() alone too), not for the next user to log in. Without a session, the
     * application's.
     */
    public function stateKey(): string
    {
        $secret = $this->sessionSecret();

        return $secret === null ? $this->key() : hash_hmac('sha256', 'nitro-fusion:session|'.$secret.'|'.$this->authIdentity(), $this->key());
    }

    /**
     * The keys state from the browser may be signed with: in a session, that session's only (state
     * signed with the application's key alone would be good in every session, for ever); without a
     * session, the application's.
     *
     * @return list<string>
     */
    public function stateKeys(): array
    {
        return [$this->stateKey()];
    }

    /** Who is logged in (the default guard), for the state key: '' for a guest. */
    private function authIdentity(): string
    {
        if (! $this->app->bound('auth')) {
            return '';
        }

        try {
            $id = $this->app->make('auth')->guard()->id();
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($id) ? 'user:'.$id : '';
    }

    /** How long a snapshot is good for, in seconds (fusion.state.lifetime); null: as long as its session. */
    public function stateLifetime(): ?int
    {
        $lifetime = $this->config('state.lifetime');

        return $lifetime === null ? null : (int) $lifetime;
    }

    /**
     * This session's secret for signing state, made the first time it is needed and kept in the
     * session: a login keeps it (the session migrates), a logout ends it (the session is flushed).
     * Null when state is not bound to the session, or there is no session.
     */
    private function sessionSecret(): ?string
    {
        if (! $this->config('state.bind_to_session', true) || ! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        if (! $request instanceof Request || ! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $secret = $session->get('fusion.secret');

        if (! is_string($secret) || $secret === '') {
            $secret = Str::random(40);
            $session->put('fusion.secret', $secret);
        }

        return $secret;
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
            $is = $props['is'] ?? throw new InvalidArgumentException('<fusion:dynamic-component> needs :is, the name of the component to render');
            unset($props['is']);

            if (! is_string($is)) {
                throw new UnplaceableComponent('<fusion:dynamic-component> :is names a component: a string, not '.get_debug_type($is).'.');
            }

            if ($is === '') {
                return new HtmlString('');
            }

            $name = $is;
            $class = class_exists($name) ? ltrim($name, '\\') : $this->registry->class($name);

            if ($class === null || $this->registry->isPageClass($class)) {
                throw new UnplaceableComponent($class === null
                    ? "<fusion:dynamic-component> :is names [{$name}], which is not a Fusion component."
                    : "[{$name}] is a page: pages are routed (Route::fusion()), not placed in views. Place a component instead.");
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

    // ------------------------------------------------------------ tags with content (Rendering\Tags)

    /**
     * <fusion:name ...> with content: the content renders (here, in this view) into output buffers,
     * one per slot, until </fusion:name> renders the component with them.
     */
    public function startTag(string $name, array $props, mixed $key, int $number, ?string $hydrate = null): string
    {
        return $this->tags->start($name, $props, $key, $number, $hydrate);
    }

    /** <fusion:slot name="footer"> inside a tag with content. */
    public function startSlot(string $name): string
    {
        return $this->tags->startSlot($name);
    }

    public function endSlot(): string
    {
        return $this->tags->endSlot();
    }

    /** </fusion:name>: the component, with what was between its tags as its default slot. */
    public function endTag(string $name): HtmlString
    {
        return $this->tags->end($name);
    }

    // ------------------------------------------------------------ portals (Rendering\Portals)

    /** The @portal('modals') directive: where the content teleported to it goes. */
    public function portal(string $name): string
    {
        return $this->portals->portal($name);
    }

    /** The @teleport('modals') directive: its content renders into a buffer, for the portal. */
    public function startTeleport(string $name): string
    {
        return $this->portals->startTeleport($name);
    }

    public function endTeleport(): string
    {
        return $this->portals->endTeleport();
    }

    /** Each portal's placeholder becomes the content teleported to it, once the response is rendered. */
    public function fillPortals(string $body): string
    {
        return $this->portals->fill($body);
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

            return $this->renderer->render($component, $meta, $this->stateKey(), $memo, $attributes);
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

        return $this->renderer->render($component, $meta, $this->stateKey(), $this->pageMemo());
    }

    /** A component placed in a view (<fusion:...>, @fusion, a dynamic component): not a page. */
    private function placeable(string $name): ComponentMeta
    {
        $meta = $this->meta($name);

        if ($meta->page) {
            throw new InvalidArgumentException("[{$meta->name}] is a page: pages are routed (Route::fusion()), not placed in views. Place a component instead.");
        }

        if ($meta->store) {
            throw new InvalidArgumentException("[{$meta->name}] is a store: components use it through a property of its type (public Cart \$cart), it is not placed in views.");
        }

        return $meta;
    }

    // ------------------------------------------------------------ stores

    /**
     * This request's store of a class: the same one for every component that uses it, made with
     * its mount() (on the server) the first time one asks.
     */
    public function store(string $class): Store
    {
        $meta = $this->meta($class);

        if (! $meta->store) {
            throw new InvalidArgumentException("{$meta->class} is not a store (a class extending ".Store::class.').');
        }

        if (! isset($this->stores[$meta->name])) {
            /** @var Store $store */
            $store = $this->app->make($meta->class);
            $store->fusion()->id = $meta->name;
            $store->fusion()->name = $meta->name;
            $this->stores[$meta->name] = $store;
            Hooks::call($store, 'boot');

            if ($meta->hasMount) {
                $this->app->call([$store, 'mount']);
            }
        }

        return $this->stores[$meta->name];
    }

    /**
     * A store as the browser sent it (a #[Server] call): the one this request's components use.
     * Signed again for the page it was made for ($memo), not for the endpoint.
     *
     * @param  array<string, mixed>  $memo
     */
    public function adoptStore(Store $store, array $memo = []): void
    {
        $this->stores[$store->fusion()->name] = $store;
        $this->storeMemos[$store->fusion()->name] = $memo;
    }

    /** The component's store properties (public Cart $cart), given this request's stores. */
    public function giveStores(Component $component, ComponentMeta $meta): void
    {
        foreach ($meta->stores as $property => $class) {
            $component->{$property} = $this->store($class);
        }
    }

    /**
     * The stores this request made or took from the browser, signed for it: the page sends them
     * with its components (the browser makes those it does not have yet).
     *
     * @return array<string, array> browser name => signed envelope
     */
    public function storeSnapshots(): array
    {
        $snapshots = [];

        foreach ($this->stores as $name => $store) {
            $meta = $this->meta($name);
            $memo = $this->storeMemos[$name] ?? $this->pageMemo();
            $snapshots[$this->browserName($name)] = json_decode(Snapshot::capture($store, $meta, $memo)->toJson($this->stateKey(), $this->browserName($name), $this->names()), true);
        }

        return $snapshots;
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
        $this->giveStores($component, $meta);
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
    public function applyUrl(Component $component, ComponentMeta $meta): void
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

    /** Whether the component's view places a child with mount(), which only the server can create. */
    public function placesMountedChildren(ComponentMeta $meta): bool
    {
        return $this->pages->hasServerChildren($meta, mountOnly: true);
    }

    /** Whether the component's view places a child that needs the server's state (mount() or #[Server] methods). */
    public function placesServerChildren(ComponentMeta $meta): bool
    {
        return $this->pages->hasServerChildren($meta);
    }

    /**
     * The signed state of the children the component's view places that need one (mount() or
     * #[Server] methods), by id: its view rendered on the server, for the page of $memo (the
     * component's own, from its snapshot), so the children's calls re-check that page's middleware.
     * The browser takes them for children it made itself, which a #[Server] call needs.
     *
     * @param  array<string, mixed>  $memo
     * @return array<string, array>
     */
    public function childrenOf(Component $component, ComponentMeta $meta, array $memo): array
    {
        $previous = $this->renderingFor;
        $this->renderingFor = $memo;

        try {
            return $this->renderer->collectChildren(fn () => $this->renderer->render($component, $meta, $this->stateKey(), $memo));
        } catch (\Throwable $e) {
            /**
             * The state may hold what the browser changed: props from it that do not fit a child
             * (its mount() parameters, its typed properties), or a dynamic component's name that
             * names none, are the request's fault, not the server's. Anything else is a real error.
             */
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof UnplaceableComponent || ($cause instanceof \TypeError && preg_match('/::mount\(\): Argument #\d+|^Cannot assign .+ to property .+ of type /', $cause->getMessage()) === 1)) {
                    throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'A child\'s props, from the state the browser sent, do not fit it.', $e);
                }
            }

            throw $e;
        } finally {
            $this->renderingFor = $previous;
        }
    }

    /** The page the components were rendered for: their #[Server] calls re-check its middleware. */
    public function pageMemo(): array
    {
        if ($this->renderingFor !== null) {
            return $this->renderingFor;
        }

        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        if (! $request instanceof Request || $request->route() === null) {
            return [];
        }

        return ['route' => ['method' => $request->getMethod(), 'uri' => $request->getRequestUri()]];
    }

    // ------------------------------------------------------------ pages (Http\PageResponses)

    /**
     * A routed page (Route::fusion()): mount() with the route's parameters, then the component
     * inside its layout; or, for a fusion:navigate visit, only what the browser needs to render
     * the page itself.
     */
    public function page(Component $component, array $parameters): Response
    {
        return $this->pages->page($component, $parameters);
    }

    /**
     * A merge prop from a page's mount() ($this->merge(), $this->deepMerge(), $this->scroll()).
     *
     * @internal
     */
    public function mergeProperty(Component $page, string $property, Merge $merge): Merge
    {
        return $this->pages->mergeProperty($page, $property, $merge);
    }

    /**
     * A page property loaded after the page is shown ($this->defer()).
     *
     * @internal
     */
    public function deferProperty(Component $page, string $property, Closure $value): void
    {
        $this->pages->deferProperty($page, $property, $value);
    }

    /**
     * A page reloading its properties in the browser (X-Fusion-Partial): the ones it wants, an
     * empty list for all of them. Null for any other request.
     *
     * @return list<string>|null
     */
    public function partial(): ?array
    {
        return $this->pages->partial();
    }

    /** Whether the request needs the page property: any request but a partial reload of others. */
    public function wants(string $property): bool
    {
        return $this->pages->wants($property);
    }

    /**
     * The layout a page renders in, as the browser compares it (fusion:page): a markup-first
     * visit renders the page at once only into the same one.
     */
    public function pageLayout(ComponentMeta $meta): string
    {
        return $this->pages->pageLayout($meta);
    }

    // ------------------------------------------------------------ the page's head (Rendering\PageHead)

    /**
     * A client page's title and head tags (#[Navigate('client')]), worked out at build time.
     *
     * @return array{title: ?string, head: ?string}
     *
     * @throws Compiler\CompileException when its layout cannot be rendered outside a request
     */
    public function clientHead(ComponentMeta $meta): array
    {
        return $this->head->forClientPage($meta);
    }

    /** @fusionHead: the page's <title> and the tags search engines and link previews read. */
    public function head(): HtmlString
    {
        return $this->head->html();
    }

    // ------------------------------------------------------------ assets (Http\Scripts)

    /** @fusionScripts: the runtime and the application's compiled components. */
    public function scripts(): HtmlString
    {
        return $this->scripts->html();
    }

    /**
     * What the browser's Laravel helpers need (routes, translations, public config, URLs), and the
     * shared and flash data.
     *
     * @return array<string, mixed>
     */
    public function globals(): array
    {
        return $this->scripts->globals();
    }

    // ------------------------------------------------------------ shared and flash data (State\SharedData)

    /**
     * Data every component can read with $this->shared(), on the server and in the browser, as
     * Inertia's shared props. Sent to the browser with every page, visit and #[Server] call:
     * nothing secret.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function share(string|array $key, mixed $value = null): void
    {
        $this->data->share($key, $value);
    }

    /** The shared data, or one value of it (dot notation), with the closures called. */
    public function shared(?string $key = null, mixed $default = null): mixed
    {
        return $this->data->shared($key, $default);
    }

    /**
     * Shared data sent once (Fusion::once()), as Inertia's once props: the browser keeps it, and
     * a response neither calls the closure for the browser nor sends it while the browser has it.
     */
    public function once(Closure $value): Once
    {
        return new Once($value);
    }

    /** Share data sent once: Fusion::share($key, Fusion::once($value)). */
    public function shareOnce(string $key, Closure $value): Once
    {
        return $this->data->shareOnce($key, $value);
    }

    /** The shared data as the browser reads it, without the once data it has. */
    public function sharedForBrowser(): mixed
    {
        return $this->data->sharedForBrowser();
    }

    /**
     * The once data's keys, and when the browser asks for each again.
     *
     * @return array<string, int|null>
     */
    public function onceForBrowser(): array
    {
        return $this->data->onceForBrowser();
    }

    /**
     * Data for the next response only, as Inertia::flash(): a toast after a save.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function flash(string|array $key, mixed $value = null): void
    {
        $this->data->flash($key, $value);
    }

    /** The flash data, or one value of it (dot notation). */
    public function flashed(?string $key = null, mixed $default = null): mixed
    {
        return $this->data->flashed($key, $default);
    }

    /** The flash data for the browser, delivered with this response. */
    public function flashForBrowser(): mixed
    {
        return $this->data->flashForBrowser();
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
