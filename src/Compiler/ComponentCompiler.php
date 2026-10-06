<?php

namespace Nitro\Fusion\Compiler;

use BackedEnum;
use Closure;
use Nitro\Fusion\Build\Obfuscator;
use Nitro\Fusion\Compiler\Template\TemplateCompiler;
use Nitro\Fusion\Lifecycle\Hooks;
use Nitro\Fusion\Support\Wire;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use UnitEnum;

/**
 * Compiles a component (its class and its view) to the definition the browser runtime loads:
 *
 *   // App\Fusion\Students\Form · view fusion.students.form
 *   Fusion.define("students.form", {
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
     * @param  bool  $parseTemplates  templates as JSON strings for JSON.parse (fusion:build), not object literals
     * @param  (Closure(string): ?string)|null  $componentName  a component class to its name (dispatch()->to(X::class))
     */
    public function __construct(
        private readonly Closure $isBladeDirective,
        private readonly bool $parseTemplates = false,
        private readonly ?Closure $componentName = null,
        /** An obfuscated build's names for methods and components (null: their own) */
        private readonly ?Obfuscator $names = null,
        /** Clone mode (fusion.rendering = 'clone'): views' static parts marked for the browser to copy */
        private readonly bool $cloneStatic = false,
        /**
         * A page a visit renders at once (markup first, or client): its definition's extra fields, the
         * layout it renders into (markup) and a client page's title and head (client); null for others.
         */
        private readonly ?Closure $atOnce = null,
        /** @var (Closure(string): ComponentMeta)|null a store's metadata by class (the stores a component uses) */
        private readonly ?Closure $meta = null,
        /** @var list<string> the application's own fusion: attributes (fusion.attributes, checked by Globals::customAttributes()) */
        private readonly array $attributes = [],
    ) {
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
        $public = array_values(array_filter($meta->browserMethods, fn (string $name) => (new ReflectionMethod($meta->class, $name))->isPublic()));
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
            .'Fusion.store('.json_encode($scope->browserComponent($meta->name)).", {\n".implode(",\n", $fields).",\nmethods: {\n{$methodList}\n}\n});";
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
    public array $uses = ['routes' => [], 'lang' => [], 'config' => [], 'functions' => [], 'classes' => []];

    /** @var array<string, string> "Class::method" => its compiled function: the #[Browser] class and enum methods reached so far */
    private array $classMethods = [];

    /** @var array<string, list<string>> "Class::method" => the keys its own code reaches (BrowserClasses) */
    private array $classMethodUses = [];

    /** @var array<string, CompileException> "Class::method" => why it does not compile: every component reaching it fails with it */
    private array $brokenClassMethods = [];

    /** @var array<class-string, true> the #[Browser] enums reached: each is defined with all its cases */
    private array $enums = [];

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
     *   Fusion.classes["App\\Support\\Money"] = { format: function ($cents) {...} };
     *   Fusion.classes["App\\Enums\\Status"] = P.defineEnum("App\\Enums\\Status", [["Draft", "draft"], ...], {static methods}, {methods});
     */
    public function compileClasses(): string
    {
        $classes = array_fill_keys(array_keys($this->enums), []);

        foreach ($this->classMethods as $key => $js) {
            [$class, $method] = BrowserClasses::split($key);
            $classes[$class][$method] = $js;
        }

        ksort($classes);
        $scope = (new Scope)->withNames($this->names);
        $list = fn (array $methods) => implode(",\n", array_map(fn ($name, $js) => json_encode($scope->browserMethod($name)).': '.$js, array_keys($methods), $methods));
        $definitions = [];

        foreach ($classes as $class => $methods) {
            ksort($methods);
            $name = json_encode($scope->browserClass($class), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (! enum_exists($class)) {
                $definitions[] = '// '.$class." · #[Browser]\n"."Fusion.classes[{$name}] = {\n".$list($methods)."\n};";

                continue;
            }

            $static = array_filter($methods, fn (string $method) => (new ReflectionMethod($class, $method))->isStatic(), ARRAY_FILTER_USE_KEY);
            $cases = array_map(fn (UnitEnum $case) => $case instanceof BackedEnum ? [$case->name, $case->value] : [$case->name], $class::cases());

            $definitions[] = '// '.$class." · #[Browser] enum\n"
                ."Fusion.classes[{$name}] = P.defineEnum({$name}, ".json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                .", {\n".$list($static)."\n}, {\n".$list(array_diff_key($methods, $static))."\n});";
        }

        return implode("\n\n", $definitions);
    }

    /**
     * What compileClasses() defines: each method compiled ("Class::method") and each enum
     * ("Enum::"), sorted.
     *
     * @return list<string>
     */
    public function compiledClasses(): array
    {
        $keys = [...array_keys($this->classMethods), ...array_map(BrowserClasses::definition(...), array_keys($this->enums))];
        sort($keys);

        return $keys;
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

        $this->compileClassMethods(array_keys($reached));
        $this->uses['classes'] += $reached;

        return $js;
    }

    /**
     * Everything the keys reach, compiled: methods, what they call, the enums whose cases they use,
     * and for "$value->label()", the label() of each of those enums. One of those enums must have
     * it: in the browser, only an enum case has methods.
     *
     * @param  list<string>  $keys  (BrowserClasses)
     */
    private function compileClassMethods(array $keys): void
    {
        $seen = [];
        $enums = [];
        $called = [];
        $queue = $keys;

        do {
            while ($queue !== []) {
                $key = array_shift($queue);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                [$class, $method] = BrowserClasses::split($key);

                if ($class === '*') {
                    [$name, $file, $line] = BrowserClasses::calledAt($method);
                    $called[$name] ??= [$file, $line];
                } elseif ($method === '') {
                    $enums[$class] = true;
                } else {
                    $this->compileClassMethod($class, $method, $key);
                    array_push($queue, ...$this->classMethodUses[$key]);
                }
            }

            foreach (array_keys($enums) as $enum) {
                foreach (array_keys($called) as $method) {
                    if (self::caseMethod($enum, $method) && ! isset($seen[BrowserClasses::key($enum, $method)])) {
                        $queue[] = BrowserClasses::key($enum, $method);
                    }
                }
            }
        } while ($queue !== []);

        foreach ($called as $method => [$file, $line]) {
            if (array_filter(array_keys($enums), fn (string $enum) => self::caseMethod($enum, $method)) === []) {
                throw new CompileException("->{$method}() is called on a value in the browser, but only an enum case has methods there, and "
                    .($enums === [] ? 'this code uses no #[Browser] enum' : 'no #[Browser] enum this code uses ('.implode(', ', array_keys($enums)).") has a public {$method}()")
                    .'. Data from the server arrives as arrays: call methods on $this, or in a #[Server] method', $file, $line);
            }
        }

        $this->enums += $enums;
    }

    /** Whether $method is a public instance method of $enum, as written (a case's method in the browser). */
    private static function caseMethod(string $enum, string $method): bool
    {
        if (! method_exists($enum, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($enum, $method);

        return $reflection->getName() === $method && $reflection->isPublic() && ! $reflection->isStatic();
    }

    /**
     * A static method of a #[Browser] class or enum, or an enum's instance method (a case's), once.
     *
     * @param  class-string  $class
     */
    private function compileClassMethod(string $class, string $method, string $key): void
    {
        if (isset($this->classMethods[$key])) {
            return;
        }

        if (isset($this->brokenClassMethods[$key])) {
            throw $this->brokenClassMethods[$key];
        }

        $reflection = new ReflectionMethod($class, $method);
        $enum = enum_exists($class);
        $instanceMethods = $enum ? array_values(array_map(fn (ReflectionMethod $m) => $m->getName(), array_filter((new ReflectionClass($class))->getMethods(), fn (ReflectionMethod $m) => ! $m->isStatic()))) : [];
        $scope = new Scope(clientMethods: $instanceMethods, file: (string) $reflection->getFileName(), constants: (new ReflectionClass($class))->getConstants(), names: $this->names);
        $transpiler = new Transpiler($scope);

        try {
            $node = BrowserClasses::methodNode($class, $method);
            $js = match (true) {
                $reflection->isStatic() => $transpiler->staticMethod($node, $scope, $class),
                $enum => $transpiler->enumMethod($node, $scope, $class),
                default => throw new CompileException("{$class}::{$method}() is not static", $reflection->getFileName() ?: null, $reflection->getStartLine() ?: null),
            };
        } catch (CompileException $e) {
            throw $this->brokenClassMethods[$key] = new CompileException(
                "{$class}::{$method}() runs in the browser (browser code calls it) but cannot be compiled: {$e->reason}",
                $e->sourceFile,
                $e->sourceLine,
            );
        }

        $uses = array_keys($transpiler->uses['classes']);

        /** An enum's method: its enum is defined, and the methods it calls on $this are compiled. */
        if ($enum) {
            $uses[] = BrowserClasses::definition($class);

            foreach ($transpiler->calledMethods as $called) {
                $uses[] = BrowserClasses::key($class, $called);
            }
        }

        $this->classMethods[$key] = $js;
        $this->classMethodUses[$key] = array_values(array_unique($uses));
        $this->use($transpiler->uses);
    }

    /** $viewSource: a Blade view's, compiled here; null for a view the app's build compiles (Vue, React, Solid). */
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

        /** mounted() and destroyed(): the browser calls them (compiled, or #[Server]). */
        $hooks = array_values(array_intersect(Hooks::BROWSER, [...$meta->browserMethods, ...$meta->serverMethods]));

        /** A view the app's build compiles may call any public method (and they the others they call). */
        $public = array_values(array_filter($meta->browserMethods, fn (string $method) => (new \ReflectionMethod($meta->class, $method))->isPublic()));

        $methods = $this->methods($meta, $scope, [
            /** What the view calls: a Blade view's, those it names; another's, the public ones. */
            ...($templates === null ? $public : $templates->calledMethods),
            ...$this->listeners($meta),
            ...$this->updatedHooks($meta),
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
            'hooks' => $hooks,
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
            .'Fusion.define('.json_encode($scope->browserComponent($meta->name)).", {\n".implode(",\n", $fields).",\nmethods: {\n{$methodList}\n}{$view}\n});";
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

            /** An enum: the state holds its value; the component reads it as the case (Fusion.classes[enum]). */
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
                ], fn ($v) => $v !== null) + ($property->url->hasExcept() ? ['except' => Wire::encode($property->url->except)] : []);
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
                $event = (string) preg_replace_callback('/\{([\w.]+)\}/', function (array $m) use ($meta, $scope, $event) {
                    if (! $scope->isProperty(explode('.', $m[1])[0])) {
                        throw new CompileException("#[On('{$event}')] on {$meta->class}: {{$m[1]}} names no public property: the channel reads the component's state.");
                    }

                    return '{'.$scope->browserPath($m[1]).'}';
                }, $event);
            }

            $listeners[$event] = array_map($scope->browserMethod(...), $methods);
        }

        return $listeners;
    }

    /** @return list<string> */
    private function listeners(ComponentMeta $meta): array
    {
        return array_values(array_filter(array_merge(...array_values($meta->listeners) ?: [[]]), fn ($m) => ! $meta->isServerMethod($m)));
    }

    /** updatedEmail() runs after fusion:model or $set changes $email, and updated($property, $value) after any change. */
    private function updatedHooks(ComponentMeta $meta): array
    {
        return array_values(array_filter($meta->browserMethods, fn ($m) => preg_match('/^updated([A-Z]|$)/', $m) === 1));
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

            $node = $this->methodNode($meta->class, $name);
            $file = (new ReflectionMethod($meta->class, $name))->getFileName() ?: $meta->file;
            $transpiler = new Transpiler($scope);

            try {
                $compiled[$name] = $transpiler->method($node, $scope->forFile($file));
            } catch (CompileException $e) {
                throw new CompileException(
                    "{$name}() runs in the browser (the view or another browser method uses it) but cannot be compiled: "
                    .$e->getMessage().'. Mark it #[Server] if it needs the server.',
                );
            }

            array_push($queue, ...$transpiler->calledMethods);
            $this->use($transpiler->uses);
        }

        return $compiled;
    }

    private function methodNode(string $class, string $method): Stmt\ClassMethod
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = (string) $reflection->getFileName();

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        } catch (Throwable $e) {
            throw new CompileException('Cannot parse the component: '.$e->getMessage(), $file);
        }

        /** Names as the file's imports make them (Card::class: its full name), kept as written. */
        (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($ast);

        /** Reflection starts at "function"; a node with attributes starts at its first attribute. */
        $line = $reflection->getStartLine();

        foreach ((new NodeFinder)->findInstanceOf($ast, Stmt\ClassMethod::class) as $node) {
            if ($node->name->toString() === $method && $node->getStartLine() <= $line && $line <= $node->getEndLine()) {
                return $node;
            }
        }

        throw new CompileException("Cannot find the source of {$method}()", $file, $reflection->getStartLine());
    }
}
