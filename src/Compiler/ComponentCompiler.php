<?php

namespace Nitro\Compiler;

use Closure;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Support\Wire;
use ReflectionMethod;

/**
 * Compiles a component (its class and its view) to the definition the browser runtime loads:
 *
 *   // App\Nitro\Students\Form · view nitro.students.form
 *   Nitro.define("students.form", {
 *       props: {...}, server: [...], listeners: {...}, mount: true,
 *       methods: { reset: function () {...} },
 *       template: {t: "r", h: [...]},           // the view's markup, as a tree (TemplateCompiler)
 *       expressions: [function ($this) {...}],  // its dynamic parts
 *       subs: [["count"], ...],                 // what each reads (Subscriptions)
 *   });
 *
 * Only methods the browser can reach are compiled: those the view calls or binds as handlers,
 * #[On] listeners, updatedProperty() hooks, and what those call in turn. A server-only helper
 * never has to compile. The same goes for #[Browser] classes: only the static methods browser
 * code calls are compiled, once, for the main bundle (compileClasses()).
 */
final class ComponentCompiler
{
    /**
     * @param  Closure(string): bool  $isBladeDirective
     * @param  bool  $parseTemplates  templates as JSON strings for JSON.parse (nitro:build), not object literals
     * @param  (Closure(string): ?string)|null  $componentName  a component class to its name (dispatch()->to(X::class))
     */
    public function __construct(
        private readonly Closure $isBladeDirective,
        private readonly bool $parseTemplates = false,
        private readonly ?Closure $componentName = null,
        /** An obfuscated build's names for methods and components (null: their own) */
        private readonly ?Obfuscator $names = null,
        /** Clone mode (nitro.rendering = 'clone'): views' static parts marked for the browser to copy */
        private readonly bool $cloneStatic = false,
        /**
         * A page a visit renders at once (markup first, or client): its definition's extra fields, the
         * layout it renders into (markup) and a client page's title and head (client); null for others.
         */
        private readonly ?Closure $atOnce = null,
        /** @var (Closure(string): ComponentMeta)|null a store's metadata by class (the stores a component uses) */
        private readonly ?Closure $meta = null,
        /** @var list<string> the application's own nitro: attributes (nitro.attributes, checked by Globals::customAttributes()) */
        private readonly array $attributes = [],
    ) {
        $this->uses = Uses::none();
        $this->classes = new BrowserClassCompiler($names, $this->use(...));
    }

    /**
     * A store (extends Store): no view; its state, and its methods for the components that use it.
     * Every public method and #[Computed] value is compiled (any component may call it); what they
     * call, too.
     */
    public function compileStore(ComponentMeta $meta): string
    {
        return $this->reaching(fn () => $this->compileStoreDefinition($meta));
    }

    private function compileStoreDefinition(ComponentMeta $meta): string
    {
        $scope = ($this->componentName === null ? $meta->scope() : $meta->scope()->withComponentNames($this->componentName))->withNames($this->names);
        $public = $meta->publicMethods();
        $methods = $this->methods($meta, $scope, [...$public, ...$meta->computed]);

        $this->names?->assertDistinct([...$meta->browserMethods, ...$meta->serverMethods], 'method');
        $this->names?->assertDistinct(array_keys($meta->properties), 'property');
        $methods = array_combine(array_map($scope->browserMethod(...), array_keys($methods)), $methods);

        $definition = [
            'props' => $this->props($meta, $scope),
            'server' => array_map($scope->browserMethod(...), $meta->serverMethods),
        ];

        /** #[Persist] and #[Sync]: the properties the runtime's plugins keep and share. */
        foreach (['persist' => $meta->persist, 'sync' => $meta->sync] as $field => $properties) {
            if ($properties !== []) {
                $definition[$field] = array_map($scope->browserProperty(...), $properties);
            }
        }

        $fields = [];

        foreach ($definition as $name => $value) {
            $fields[] = $name.': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $methodList = implode(",\n", array_map(fn ($name, $js) => json_encode($name).': '.$js, array_keys($methods), $methods));

        return '// '.$meta->class." · store\n"
            .'Nitro.store('.json_encode($scope->browserComponent($meta->name)).", {\n".implode(",\n", $fields).",\nmethods: {\n{$methodList}\n}\n});";
    }

    /** The scope with the stores the component uses: each store's name and members. */
    private function withStores(Scope $scope, ComponentMeta $meta): Scope
    {
        if ($meta->stores === []) {
            return $scope;
        }

        if ($this->meta === null) {
            throw new \LogicException('Compiling a component that uses stores needs their metadata (the meta closure).');
        }

        $stores = [];

        foreach ($meta->stores as $property => $class) {
            $store = ($this->meta)($class);
            $stores[$property] = ['name' => $store->name, 'scope' => $store->scope()];

            /** Its enum properties: the component reads them as cases, so their enums are its too. */
            foreach ($store->properties as $storeProperty) {
                if ($storeProperty->kind === PropertyMeta::ENUM) {
                    $this->uses['classes'][BrowserClasses::definition($storeProperty->types[0])] = true;
                }
            }
        }

        return $scope->withStores($stores);
    }

    /**
     * What the compiled code's Laravel helpers name, across every component compiled so far: the
     * page ships these (route definitions, translations, config values).
     *
     * @var array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>, classes: array<string, true>}
     */
    public array $uses;

    /** The #[Browser] classes and enums the components reach, compiled once for the bundle. */
    private readonly BrowserClassCompiler $classes;

    /**
     * A component, and the #[Browser] class methods it reaches (compiled once for the bundle,
     * compileClasses()). One that does not compile fails the component.
     */
    public function compile(ComponentMeta $meta, ?string $viewSource, string $viewFile): string
    {
        return $this->reaching(fn () => $this->compileComponent($meta, $viewSource, $viewFile));
    }

    /**
     * The #[Browser] classes and enums the code reached, as the main bundle defines them:
     *
     *   Nitro.classes["App\\Support\\Money"] = { format: function ($cents) {...} };
     *   Nitro.classes["App\\Enums\\Status"] = P.defineEnum("App\\Enums\\Status", [["Draft", "draft"], ...], {static methods}, {methods});
     */
    /**
     * The #[Browser] classes and enums the code reached, as the main bundle defines them
     * (BrowserClassCompiler::definitions()).
     */
    public function compileClasses(): string
    {
        return $this->classes->definitions();
    }

    /**
     * What compileClasses() defines: each method compiled ("Class::method") and each enum
     * ("Enum::"), sorted.
     *
     * @return list<string>
     */
    public function compiledClasses(): array
    {
        return $this->classes->compiled();
    }

    /**
     * Compiles a component or a store, then the #[Browser] class and enum methods its code calls
     * (and what those call in turn). Their uses count only once the component compiles.
     *
     * @param  Closure(): string  $compile
     */
    private function reaching(Closure $compile): string
    {
        $outer = $this->uses['classes'];
        $this->uses['classes'] = [];

        try {
            $js = $compile();
            $reached = $this->uses['classes'];
        } finally {
            $this->uses['classes'] = $outer;
        }

        $this->classes->reach(array_keys($reached));
        $this->uses['classes'] += $reached;

        return $js;
    }

    /** $viewSource: a Blade view's, compiled here; null for a view the app's build compiles (a JavaScript framework's). */
    private function compileComponent(ComponentMeta $meta, ?string $viewSource, string $viewFile): string
    {
        $scope = $this->componentName === null ? $meta->scope() : $meta->scope()->withComponentNames($this->componentName);
        $scope = $this->withStores($scope->withNames($this->names), $meta);
        $templates = null;

        if ($viewSource !== null) {
            $templates = new TemplateCompiler($this->isBladeDirective, $this->cloneStatic, $this->attributes);
            $templates->compile($viewSource, $scope, $viewFile);
            $this->use($templates->uses());
        }

        /** The hooks the browser calls (ComponentHooks, PropertyHooks): compiled, or #[Server]. */
        $hooks = $meta->componentHooks();
        $propertyHooks = $meta->propertyHooks();

        /** A view the app's build compiles may call any public method (and they the others they call). */
        $public = $meta->publicMethods();

        $methods = $this->methods($meta, $scope, [
            /** What the view calls: a Blade view's, those it names; another's, the public ones. */
            ...($templates === null ? $public : $templates->calledMethods),
            ...$this->listeners($meta),
            ...array_column($propertyHooks, 'method'),
            ...$hooks,
        ]);

        /** The browser's names for the methods (an obfuscated build: aliases, but for the hooks it calls by name). */
        $this->names?->assertDistinct([...$meta->browserMethods, ...$meta->serverMethods], 'method');
        $this->names?->assertDistinct(array_keys($meta->properties), 'property');
        $methods = array_combine(array_map($scope->browserMethod(...), array_keys($methods)), $methods);

        $definition = [
            'page' => $meta->page,
            'props' => $this->props($meta, $scope),
            'server' => array_map($scope->browserMethod(...), $meta->serverMethods),
            'listeners' => (object) $this->listenersFor($meta, $scope),
            'mount' => $meta->hasMount,
            'modelable' => $meta->modelable === null ? null : $scope->browserProperty($meta->modelable),
            /** hook => the method that is it, by the browser's name (a #[Server] one is in 'server'). */
            'hooks' => (object) array_combine($hooks, array_map($scope->browserMethod(...), $hooks)),
            /** the browser's name of a property ('*' for every one) => when (updating, updated) => the method. */
            'propertyHooks' => (object) $this->propertyHooksFor($propertyHooks, $scope),
        ];

        /**
         * A view the app's build compiles: its engine, its name (the engine loads it), and the
         * browser's names for what it uses by the PHP's names (an obfuscated build's aliases).
         */
        if ($templates === null) {
            $properties = array_keys($meta->properties);
            $definition['engine'] = $meta->engine;
            $definition['view'] = $meta->view;
            $definition['names'] = [
                'properties' => (object) array_combine($properties, array_map($scope->browserProperty(...), $properties)),
                'methods' => (object) array_combine($public, array_map($scope->browserMethod(...), $public)),
                'server' => (object) array_combine($meta->serverMethods, array_map($scope->browserMethod(...), $meta->serverMethods)),
                'computed' => (object) array_combine($meta->computed, array_map($scope->browserMethod(...), $meta->computed)),
            ];
        }

        /** The stores it uses: by the property holding each, the store's name. */
        if ($meta->stores !== []) {
            $definition['stores'] = [];

            foreach ($scope->stores as $property => $store) {
                $definition['stores'][$scope->browserProperty($property)] = $scope->browserComponent($store['name']);
            }
        }

        /** A page rendered at once on a visit (markup first, or client), into this layout only. */
        $definition += ($this->atOnce === null ? null : ($this->atOnce)($meta)) ?? [];

        $fields = [];

        foreach ($definition as $name => $value) {
            $fields[] = $name.': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $methodList = implode(",\n", array_map(fn ($name, $js) => json_encode($name).': '.$js, array_keys($methods), $methods));

        /** A Blade view's template, and what each of its parts reads. */
        $view = '';

        if ($templates !== null) {
            /** A store's property reads as one key: the runtime marks it when the store changes. */
            $subscriptions = new Subscriptions($methods, array_map($scope->browserProperty(...), [...array_keys($meta->properties), ...array_keys($meta->stores)]));
            $subs = [];

            foreach ($subscriptions->ofTemplate($templates->expressions(), $templates->expressionSources()) as $index => $keys) {
                $subs[] = "/* {$index} */ ".json_encode($keys, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            $view = ",\n".$templates->fragment($this->parseTemplates).",\nsubs: [\n".implode(",\n", $subs)."\n]";
        }

        return '// '.$meta->class.' · view '.$meta->view.($templates === null ? " ({$meta->engine})" : '')."\n"
            .'Nitro.define('.json_encode($scope->browserComponent($meta->name)).", {\n".implode(",\n", $fields).",\nmethods: {\n{$methodList}\n}{$view}\n});";
    }

    /** @param  array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>, classes: array<string, true>}  $uses */
    private function use(array $uses): void
    {
        foreach ($uses as $kind => $names) {
            $this->uses[$kind] += $names;
        }
    }

    /** @return array<string, array{locked: bool, default?: mixed, url?: array<string, mixed>, remember?: true}> */
    private function props(ComponentMeta $meta, Scope $scope): array
    {
        $props = [];

        foreach ($meta->properties as $real => $property) {
            /** By the browser's name for it (an obfuscated build). */
            $name = $scope->browserProperty($real);
            $props[$name] = ['locked' => $property->locked];

            if ($property->hasDefault && in_array($property->kind, [PropertyMeta::VALUE, PropertyMeta::ENUM], true)) {
                $props[$name]['default'] = Wire::encode($property->default);
            }

            /** An enum: the state holds its value; the component reads it as the case (Nitro.classes[enum]). */
            if ($property->kind === PropertyMeta::ENUM) {
                $props[$name]['enum'] = $scope->browserClass($property->types[0]);
                $this->uses['classes'][BrowserClasses::definition($property->types[0])] = true;
            }

            /** #[Url]: the query key (the property's own name: the URL shows it anyway), and when the value is left out of the URL. */
            if ($property->url !== null) {
                $props[$name]['url'] = array_filter([
                    'as' => $property->url->as ?? $real,
                    'history' => $property->url->history ?: null,
                    'keep' => $property->url->keep ?: null,
                ], fn ($value) => $value !== null) + ($property->url->hasExcept() ? ['except' => Wire::encode($property->url->except)] : []);
            }

            if ($property->remember) {
                $props[$name]['remember'] = true;
            }
        }

        return $props;
    }

    /**
     * #[On] listeners as the browser has them: event => methods, by the browser's names. A
     * broadcast's channel ("echo-private:orders.{order.id},OrderShipped") reads properties: by
     * the browser's names too, and each must be one.
     *
     * @return array<string, list<string>>
     */
    private function listenersFor(ComponentMeta $meta, Scope $scope): array
    {
        $listeners = [];

        foreach ($meta->listeners as $event => $methods) {
            if (str_starts_with($event, 'echo')) {
                $event = (string) preg_replace_callback('/\{([\w.]+)\}/', function (array $match) use ($meta, $scope, $event) {
                    if (! $scope->isProperty(explode('.', $match[1])[0])) {
                        throw new CompileException("#[On('{$event}')] on {$meta->class}: {{$match[1]}} names no public property: the channel reads the component's state.");
                    }

                    return '{'.$scope->browserPath($match[1]).'}';
                }, $event);
            }

            $listeners[$event] = array_map($scope->browserMethod(...), $methods);
        }

        return $listeners;
    }

    /** @return list<string> */
    private function listeners(ComponentMeta $meta): array
    {
        return array_values(array_filter(array_merge(...array_values($meta->listeners) ?: [[]]), fn ($method) => ! $meta->isServerMethod($method)));
    }

    /**
     * The property hooks as the browser finds them: by its name for the property ('*' for those
     * of every property), then when they run.
     *
     * @param  list<array{method: string, when: string, property: ?string}>  $hooks
     * @return array<string, object>
     */
    private function propertyHooksFor(array $hooks, Scope $scope): array
    {
        $byProperty = [];

        foreach ($hooks as $hook) {
            $property = $hook['property'] === null ? '*' : $scope->browserProperty($hook['property']);
            $byProperty[$property][$hook['when']] = $scope->browserMethod($hook['method']);
        }

        return array_map(fn (array $when) => (object) $when, $byProperty);
    }

    /**
     * Compile what the browser can reach, following $this->method() calls.
     *
     * @param  list<string>  $seeds
     * @return array<string, string>
     */
    private function methods(ComponentMeta $meta, Scope $scope, array $seeds): array
    {
        $compiled = [];
        $queue = array_values(array_unique($seeds));

        while ($queue !== []) {
            $name = array_shift($queue);

            if (isset($compiled[$name]) || ! in_array($name, $meta->browserMethods, true)) {
                continue;
            }

            $node = MethodSource::node($meta->class, $name);
            $file = (new ReflectionMethod($meta->class, $name))->getFileName() ?: $meta->file;
            $transpiler = new Transpiler($scope);

            try {
                $compiled[$name] = $transpiler->method($node, $scope->forFile($file));
            } catch (CompileException $exception) {
                throw new CompileException(
                    "{$name}() runs in the browser (the view or another browser method uses it) but cannot be compiled: "
                    .$exception->getMessage().'. Mark it #[Server] if it needs the server.',
                );
            }

            array_push($queue, ...$transpiler->calledMethods);
            $this->use($transpiler->uses);
        }

        return $compiled;
    }

}
