<?php

namespace Nitro\Compiler;

use Nitro\Attributes\Cached;
use Nitro\Attributes\Computed;
use Nitro\Attributes\Description;
use Nitro\Attributes\Layout;
use Nitro\Attributes\LazyLoad;
use Nitro\Attributes\Modelable;
use Nitro\Attributes\Navigate;
use Nitro\Attributes\Persist;
use Nitro\Attributes\Server;
use Nitro\Attributes\Sync;
use Nitro\Attributes\Validate;
use Nitro\Attributes\View;
use Nitro\Support\ComponentHooks;
use Nitro\Support\PropertyHooks;
use Nitro\NavigationMode;
use Nitro\Store;
use Nitro\Support\Protocol;
use ReflectionMethod;

/**
 * What Nitro knows about a component class, read once from its code (ComponentReader).
 */
final class ComponentMeta
{
    /**
     * @param  array<string, PropertyMeta>  $properties
     * @param  list<string>  $serverMethods
     * @param  list<string>  $browserMethods  methods that may be compiled (if something reaches them)
     * @param  array<string, list<string>>  $listeners  event => methods
     * @param  array<string, list<array{name: string, type: ?string, kind: string, nullable: bool, optional: bool, variadic: bool}>>  $serverParameters
     * @param  list<string>  $computed  #[Computed] methods, read as $this->name
     * @param  string|null  $lazy  the chunk it is compiled into: a page's own (or its #[LazyLoad] group),
     *                             a component's #[LazyLoad] group; null for the main bundle
     * @param  bool  $page  a page (routed) rather than a component
     */
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly string $file,
        public readonly string $view,
        public readonly array $properties,
        public readonly array $serverMethods,
        public readonly array $browserMethods,
        public readonly array $listeners,
        public readonly bool $hasMount,
        public readonly ?Layout $layout,
        public readonly ?string $title,
        public readonly array $constants,
        public readonly array $serverParameters,
        /** What its view is written in (View\ViewEngine): Blade (Protocol::bladeEngine()), or a JavaScript framework's. */
        public readonly string $engine,
        public readonly array $computed = [],
        public readonly ?string $lazy = null,
        /** The #[Modelable] property: what nitro:model on its tag binds. */
        public readonly ?string $modelable = null,
        public readonly bool $page = false,
        /** #[Navigate]: this page's mode; null to follow nitro.navigation. */
        public readonly ?NavigationMode $navigate = null,
        /** #[Description]: the page's description for search results and link previews. */
        public readonly ?string $description = null,
        /**
         * #[Validate], by property: its rules by key (the property, or keys of an array in dot
         * notation) and the names messages give them.
         *
         * @var array<string, array{rules: array<string, mixed>, attributes: array<string, string>}>
         */
        public readonly array $rules = [],
        /** @var array<string, class-string<Store>> the stores it uses, by property (public Cart $cart) */
        public readonly array $stores = [],
        /** A store (extends Store): state components share, with no view. */
        public readonly bool $store = false,
        /** @var list<string> a store's properties kept in localStorage (#[Persist]) */
        public readonly array $persist = [],
        /** @var list<string> a store's properties shared with the browser's other tabs (#[Sync]) */
        public readonly array $sync = [],
        /** @var array<string, Cached> mount() or #[Server] methods whose result is kept (#[Cached]), by method */
        public readonly array $cached = [],
        /** @var list<string> #[Html] methods: made on the server, read as $this->name, sent beside the state */
        public readonly array $html = [],
    ) {
    }

    public function isHtml(string $name): bool
    {
        return in_array($name, $this->html, true);
    }

    /**
     * The names read as $this->name that are not properties: #[Computed] (worked out in the
     * browser) and #[Html] (made on the server).
     *
     * @return list<string>
     */
    public function derived(): array
    {
        return [...$this->computed, ...$this->html];
    }

    /**
     * The rules of these properties (#[Validate]), and the names messages give them.
     *
     * @param  list<string>|null  $properties  null: every property that has rules
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function rulesFor(?array $properties = null): array
    {
        $rules = [];
        $attributes = [];

        foreach ($properties === null ? $this->rules : array_intersect_key($this->rules, array_flip($properties)) as $set) {
            $rules += $set['rules'];
            $attributes += $set['attributes'];
        }

        return [$rules, $attributes];
    }

    public function isComputed(string $name): bool
    {
        return in_array($name, $this->computed, true);
    }

    /**
     * The methods a view the app's build compiles may call: the public ones that run in the
     * browser (the private ones are the class's own business).
     *
     * @return list<string>
     */
    public function publicMethods(): array
    {
        return array_values(array_filter($this->browserMethods, fn (string $method) => (new ReflectionMethod($this->class, $method))->isPublic()));
    }

    public function isServerMethod(string $method): bool
    {
        return in_array($method, $this->serverMethods, true);
    }

    /**
     * Its view gets these names from Nitro (ViewEngine::reservedNames()): a property or method
     * of the component cannot have one too, or the view would see only one of them.
     *
     * @param  list<string>  $reserved
     */
    public function assertNamesFree(array $reserved, string $engine): void
    {
        $own = [...array_keys($this->properties), ...$this->browserMethods, ...$this->serverMethods, ...$this->derived()];
        $taken = array_values(array_intersect($own, $reserved));

        if ($taken !== []) {
            $what = isset($this->properties[$taken[0]]) ? '$'.$taken[0] : $taken[0].'()';

            throw new CompileException("{$what} of {$this->class}: a {$engine} view gets {$taken[0]} from Nitro (".implode(', ', $reserved).'), so the component cannot have it too; rename it', $this->file);
        }
    }

    /**
     * The browser's component hooks it has (ComponentHooks::BROWSER): compiled, or #[Server].
     *
     * @return list<string>
     */
    public function componentHooks(): array
    {
        return array_values(array_filter(ComponentHooks::BROWSER, fn (string $hook) => in_array($hook, [...$this->browserMethods, ...$this->serverMethods], true)));
    }

    /**
     * Its property hooks (PropertyHooks): each method, when it runs, and its property (null for
     * every property).
     *
     * @return list<array{method: string, when: string, property: ?string}>
     */
    public function propertyHooks(): array
    {
        $hooks = [];

        foreach ([...$this->browserMethods, ...$this->serverMethods] as $method) {
            if (($hook = PropertyHooks::parse($method, array_keys($this->properties))) !== null) {
                $hooks[] = ['method' => $method] + $hook;
            }
        }

        return $hooks;
    }

    public function property(string $name): ?PropertyMeta
    {
        return $this->properties[$name] ?? null;
    }

    /** @return list<string> */
    public function locked(): array
    {
        return array_keys(array_filter($this->properties, fn (PropertyMeta $property) => $property->locked));
    }

    public function scope(): Scope
    {
        return new Scope(
            properties: array_keys($this->properties),
            clientMethods: $this->browserMethods,
            serverMethods: $this->serverMethods,
            file: $this->file,
            constants: $this->constants,
            locked: $this->locked(),
            /** Both read as $this->name: the browser serves #[Html] from what the server sent (component.js computed()). */
            computed: $this->derived(),
        );
    }
}
