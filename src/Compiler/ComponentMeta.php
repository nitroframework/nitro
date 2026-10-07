<?php

namespace Nitro\Fusion\Compiler;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Description;
use Nitro\Fusion\Attributes\Layout;
use Nitro\Fusion\Attributes\LazyLoad;
use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Modelable;
use Nitro\Fusion\Attributes\Navigate;
use Nitro\Fusion\Attributes\On;
use Nitro\Fusion\Attributes\Persist;
use Nitro\Fusion\Attributes\Remember;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Attributes\Sync;
use Nitro\Fusion\Attributes\Title;
use Nitro\Fusion\Attributes\Uploads;
use Nitro\Fusion\Attributes\Url;
use Nitro\Fusion\Attributes\Validate;
use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;
use Nitro\Fusion\FeatureAttribute;
use Nitro\Fusion\State\ComponentState;
use Nitro\Fusion\Store;
use Nitro\Fusion\Support\Naming;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * What Fusion knows about a component class, read once from its code.
 */
final class ComponentMeta
{
    /** Methods Fusion calls itself: never compiled for the browser, never callable from it. */
    private const LIFECYCLE = ['mount', 'boot', 'hydrate', 'dehydrate', 'render'];

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
        public readonly array $computed = [],
        public readonly ?string $lazy = null,
        /** The #[Modelable] property: what fusion:model on its tag binds. */
        public readonly ?string $modelable = null,
        public readonly bool $page = false,
        /** #[Navigate]: 'data' or 'markup' for this page; null to follow fusion.navigation. */
        public readonly ?string $navigate = null,
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
        /** What its view is written in (View\ViewEngine): 'blade', or a JavaScript framework's. */
        public readonly string $engine = 'blade',
    ) {
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

    public static function read(string $class, string $name, string $defaultView, bool $page = false): self
    {
        try {
            return self::readClass($class, $name, $defaultView, $page);
        } catch (\Error $exception) {
            /**
             * The class's own code failing as it is read: an attribute PHP cannot make (repeated,
             * on the wrong target, wrong arguments), a default value naming a constant that is not
             * there. Where it is, as any compile error. An error in Fusion's own code is not this.
             */
            $attribute = array_filter($exception->getTrace(), fn (array $frame) => ($frame['class'] ?? null) === \ReflectionAttribute::class && $frame['function'] === 'newInstance') !== [];

            if ($attribute || ! str_starts_with(realpath($exception->getFile()) ?: $exception->getFile(), dirname(__DIR__))) {
                throw new CompileException("{$class}: {$exception->getMessage()}", (string) (new ReflectionClass($class))->getFileName());
            }

            throw $exception;
        }
    }

    private static function readClass(string $class, string $name, string $defaultView, bool $page): self
    {
        $reflection = new ReflectionClass($class);
        $file = (string) $reflection->getFileName();

        if (! $reflection->isSubclassOf(Component::class) || ! $reflection->isInstantiable()) {
            throw new CompileException("{$class} is not a Fusion component (a concrete class extending ".Component::class.')', $file);
        }

        $properties = [];
        $modelable = null;
        $rules = [];
        $stores = ComponentState::stores($class);
        $isStore = $reflection->isSubclassOf(Store::class);

        if ($isStore) {
            self::assertStore($reflection, $stores, $file);
        }

        foreach (ComponentState::properties($class) as $property) {
            $url = ($property->getAttributes(Url::class)[0] ?? null)?->newInstance();

            /** #[Validate]: rules for the property itself, or rules by key when given with string keys (an array's). */
            if (($validate = ($property->getAttributes(Validate::class)[0] ?? null)?->newInstance()) !== null) {
                $field = $property->getName();
                $byKey = is_array($validate->rules) && $validate->rules !== [] && ! array_is_list($validate->rules);
                $rules[$field] = [
                    'rules' => $byKey ? $validate->rules : [$field => $validate->rules],
                    'attributes' => $validate->as === null ? [] : [$field => $validate->as],
                ];
            }
            $properties[$property->getName()] = PropertyMeta::from($property, $property->getAttributes(Locked::class) !== [], $file, $url, $property->getAttributes(Remember::class) !== [], $property->getAttributes(Uploads::class) !== []);

            if ($property->getAttributes(Modelable::class) !== []) {
                if ($modelable !== null || $properties[$property->getName()]->locked) {
                    throw new CompileException("#[Modelable] goes on one property the browser can change (\${$property->getName()} of {$class})", $file);
                }

                $modelable = $property->getName();
            }
        }

        $server = [];
        $browser = [];
        $listeners = [];
        $parameters = [];
        $computed = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === Component::class || $method->isStatic()
                || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                continue;
            }

            $methodName = $method->getName();

            /** PHP keeps properties and methods apart; the browser's component has one name for each. */
            if (isset($properties[$methodName]) && $method->isPublic()) {
                throw new CompileException("{$methodName}() has the name of a public property (\${$methodName}): in the browser they would be one; rename one of them", $file, $method->getStartLine());
            }

            foreach ($method->getAttributes(On::class) as $attribute) {
                $listeners[$attribute->newInstance()->event][] = $methodName;
            }

            if ($method->getAttributes(Computed::class) !== []) {
                $computed[] = self::computed($method, $properties, $file);
            }

            /** An attribute that works as a feature runs around a #[Server] call: on a browser method, it would never run. */
            $handler = $method->getAttributes(FeatureAttribute::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

            if ($handler !== null && $method->getAttributes(Server::class) === []) {
                throw new CompileException('#['.class_basename($handler->getName())."] on {$methodName}(): it runs around a #[Server] method's call, and {$methodName}() runs in the browser; mark it #[Server]", $file, $method->getStartLine());
            }

            if ($method->getAttributes(Server::class) !== []) {
                if (! $method->isPublic() || in_array($methodName, self::LIFECYCLE, true)) {
                    throw new CompileException("#[Server] {$methodName}() must be a public method that is not a lifecycle hook", $file, $method->getStartLine());
                }

                $server[] = $methodName;
                $parameters[$methodName] = self::parameters($method, $file);

                continue;
            }

            if (! in_array($methodName, self::LIFECYCLE, true)) {
                $browser[] = $methodName;
            }
        }

        try {
            $view = ($reflection->getAttributes(View::class)[0] ?? null)?->newInstance();
        } catch (\InvalidArgumentException $exception) {
            throw new CompileException("{$class}: {$exception->getMessage()}", $file);
        }

        if ($view !== null && $isStore) {
            throw new CompileException("{$class} is a store: it has no view, so no #[View]", $file);
        }

        $layout = $reflection->getAttributes(Layout::class)[0] ?? null;
        $title = $reflection->getAttributes(Title::class)[0] ?? null;
        $lazy = ($reflection->getAttributes(LazyLoad::class)[0] ?? null)?->newInstance();

        $navigate = ($reflection->getAttributes(Navigate::class)[0] ?? null)?->newInstance()->mode;
        $description = ($reflection->getAttributes(Description::class)[0] ?? null)?->newInstance()->description;

        if (! $page && ($layout !== null || $title !== null || $navigate !== null || $description !== null)) {
            throw new CompileException("#[Layout], #[Title], #[Description] and #[Navigate] are a page's: {$class} is a component (it renders inside a page). Move it to the pages namespace (fusion.pages) to route it.", $file);
        }

        if ($navigate !== null && ! in_array($navigate, ['data', 'markup', 'client'], true)) {
            throw new CompileException("#[Navigate] on {$class} is 'data', 'markup' or 'client', not '{$navigate}'", $file);
        }

        /** A client page renders in the browser without asking the server: it must need nothing from it. */
        if ($navigate === 'client') {
            $needs = array_filter([
                $reflection->hasMethod('mount') ? 'mount()' : null,
                $server !== [] ? '#[Server] methods ('.implode(', ', $server).')' : null,
                array_filter($properties, fn (PropertyMeta $property) => $property->url !== null) !== [] ? '#[Url] properties' : null,
            ]);

            if ($needs !== []) {
                throw new CompileException("#[Navigate('client')] on {$class}: a client page renders without asking the server, so it cannot have ".implode(', ', $needs).". Use #[Navigate('markup')]: it renders at once too, and its data follows.", $file);
            }
        }

        /** A page is a chunk of its own unless it names a group or #[LazyLoad(false)]; a component only with #[LazyLoad]. */
        $group = match (true) {
            $lazy === null => $page ? Naming::bare($name) : null,
            $lazy->group === false => null,
            default => $lazy->group ?: Naming::bare($name),
        };

        if (is_string($group) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $group) !== 1) {
            throw new CompileException("#[LazyLoad] group [{$group}] of {$class} is not a valid chunk name (letters, digits, dots, dashes and underscores)", $file);
        }

        return new self(
            $class,
            $name,
            $file,
            $view->name ?? $defaultView,
            $properties,
            $server,
            $browser,
            $listeners,
            $reflection->hasMethod('mount'),
            $layout?->newInstance(),
            $title?->newInstance()->title,
            $reflection->getConstants(),
            $parameters,
            $computed,
            $group,
            $modelable,
            $page,
            $navigate,
            $description,
            $rules,
            $stores,
            $isStore,
            self::kept($reflection, Persist::class, '#[Persist]', $properties, $isStore, $file),
            self::kept($reflection, Sync::class, '#[Sync]', $properties, $isStore, $file),
            $view->engine ?? 'blade',
        );
    }

    /**
     * #[Persist] and #[Sync]: a store's properties the browser keeps or shares. On the store, every
     * property the browser can change; on a property, that one, which the browser must be able to
     * change (not #[Locked]). A component's state is its own: they are for stores.
     *
     * @param  ReflectionClass<Component>  $reflection
     * @param  class-string  $attribute
     * @param  array<string, PropertyMeta>  $properties
     * @return list<string>
     */
    private static function kept(ReflectionClass $reflection, string $attribute, string $label, array $properties, bool $isStore, string $file): array
    {
        $onClass = $reflection->getAttributes($attribute) !== [];
        $kept = [];

        foreach ($properties as $name => $property) {
            $onProperty = $reflection->hasProperty($name) && $reflection->getProperty($name)->getAttributes($attribute) !== [];

            if (! $onClass && ! $onProperty) {
                continue;
            }

            if ($onProperty && $property->locked) {
                throw new CompileException("{$label} on \${$name} of {$reflection->getName()}: it is #[Locked], so only the server sets it", $file);
            }

            if (! $property->locked) {
                $kept[] = $name;
            }
        }

        if (($onClass || $kept !== []) && ! $isStore) {
            throw new CompileException("{$label} is for stores: {$reflection->getName()} is a component, whose state is its own. Keep what outlasts it in a store", $file);
        }

        return $kept;
    }

    /**
     * A #[Computed] method: read as a property, so it takes nothing, and it is worked out from the
     * state in the browser, so it is not #[Server] or a lifecycle hook.
     *
     * @param  array<string, PropertyMeta>  $properties
     */
    private static function computed(ReflectionMethod $method, array $properties, string $file): string
    {
        $name = $method->getName();
        $problem = match (true) {
            ! $method->isPublic() => 'must be public',
            $method->getAttributes(Server::class) !== [] => 'cannot be #[Server]: it is worked out from the state in the browser (make the server set a property instead)',
            in_array($name, self::LIFECYCLE, true) => 'cannot be a lifecycle hook',
            $method->getNumberOfRequiredParameters() > 0 => 'cannot take parameters: it is read as $this->'.$name,
            isset($properties[$name]) => 'has the name of a public property',
            default => null,
        };

        if ($problem !== null) {
            throw new CompileException("#[Computed] {$name}() {$problem}", $file, $method->getStartLine());
        }

        return $name;
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

    /**
     * The parameters of a #[Server] method, each with what it takes from the browser:
     *   'value'    a scalar or an array, checked against its type
     *   'model'    an Eloquent model, from its key, as route model binding resolves one
     *   'enum'     a backed enum, from its value
     *   'service'  any other class, from the container (nothing from the browser)
     *
     * What can never arrive refuses to build: object, iterable, callable, self and parent (nothing
     * fills them), a variadic model or enum, an abstract model, a pure enum (it has no value to send).
     */
    private static function parameters(ReflectionMethod $method, string $file): array
    {
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $where = "#[Server] {$method->getName()}(\${$parameter->getName()})";

            if ($type !== null && ! $type instanceof ReflectionNamedType) {
                throw new CompileException("#[Server] {$method->getName()}(): union and intersection parameter types are not supported", $file, $method->getStartLine());
            }

            if ($parameter->isPassedByReference()) {
                throw new CompileException("#[Server] {$method->getName()}(): parameters cannot be passed by reference", $file, $method->getStartLine());
            }

            $name = $type?->getName();

            /** Types neither the browser can send nor the container can make. */
            if (in_array($name, ['object', 'iterable', 'callable', 'self', 'parent', 'null', 'true', 'false'], true)) {
                throw new CompileException("{$where}: a {$name} parameter cannot be filled from the browser. Take a scalar, an array, a model (by its key) or a backed enum, or a service class from the container.", $file, $method->getStartLine());
            }

            $kind = match (true) {
                $name === null || $type->isBuiltin() => 'value',
                is_a($name, Model::class, true) => 'model',
                enum_exists($name) => 'enum',
                default => 'service',
            };

            $problem = match (true) {
                $kind === 'model' && (new ReflectionClass($name))->isAbstract() => "{$name} is abstract: name the model the key belongs to",
                $kind === 'enum' && ! is_subclass_of($name, BackedEnum::class) => "{$name} is a pure enum: it has no value the browser can send (make it a backed enum: enum {$parameter->getName()}: string)",
                in_array($kind, ['model', 'enum'], true) && $parameter->isVariadic() => 'a model or an enum arrives one per parameter, not variadic (take an array of keys and look them up)',
                default => null,
            };

            if ($problem !== null) {
                throw new CompileException("{$where}: {$problem}", $file, $method->getStartLine());
            }

            $parameters[] = [
                'name' => $parameter->getName(),
                'type' => $name,
                'kind' => $kind,
                'nullable' => $type === null || $type->allowsNull(),
                'optional' => $parameter->isOptional(),
                'variadic' => $parameter->isVariadic(),
            ];
        }

        return $parameters;
    }

    public function isServerMethod(string $method): bool
    {
        return in_array($method, $this->serverMethods, true);
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

    /**
     * What a store cannot have: what belongs to a component on the page (events it listens to, the
     * query string, the history entry, fusion:model on its tag, uploads), or stores of its own.
     *
     * @param  ReflectionClass<object>  $reflection
     * @param  array<string, string>  $stores
     */
    private static function assertStore(ReflectionClass $reflection, array $stores, string $file): void
    {
        $class = $reflection->getName();

        if ($stores !== []) {
            throw new CompileException("{$class} is a store: it cannot hold another store (\$".array_key_first($stores).'); components use each store themselves', $file);
        }

        foreach ($reflection->getMethods() as $method) {
            if ($method->getAttributes(On::class) !== []) {
                throw new CompileException("#[On] is for components on the page: {$class}::{$method->getName()}() is a store's. A component listens and calls the store", $file, $method->getStartLine());
            }
        }

        foreach (ComponentState::properties($class) as $property) {
            foreach ([Url::class => '#[Url]', Remember::class => '#[Remember]', Modelable::class => '#[Modelable]', Uploads::class => '#[Uploads]'] as $attribute => $label) {
                if ($property->getAttributes($attribute) !== []) {
                    throw new CompileException("{$label} is for components: \${$property->getName()} of the store {$class} cannot have it", $file);
                }
            }
        }
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
            computed: $this->computed,
        );
    }
}
