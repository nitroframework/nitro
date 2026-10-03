<?php

namespace Nitro\Fusion\Compiler;

use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Layout;
use Nitro\Fusion\Attributes\LazyLoad;
use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Modelable;
use Nitro\Fusion\Attributes\On;
use Nitro\Fusion\Attributes\Remember;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Attributes\Title;
use Nitro\Fusion\Attributes\Url;
use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;
use Nitro\Fusion\State;
use Nitro\Fusion\Support\Naming;
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
     * @param  array<string, list<array{name: string, type: ?string, nullable: bool, optional: bool, variadic: bool}>>  $serverParameters
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
    ) {
    }

    public static function read(string $class, string $name, string $defaultView, bool $page = false): self
    {
        $reflection = new ReflectionClass($class);
        $file = (string) $reflection->getFileName();

        if (! $reflection->isSubclassOf(Component::class) || ! $reflection->isInstantiable()) {
            throw new CompileException("{$class} is not a Fusion component (a concrete class extending ".Component::class.')', $file);
        }

        $properties = [];
        $modelable = null;

        foreach (State::properties($class) as $property) {
            $url = ($property->getAttributes(Url::class)[0] ?? null)?->newInstance();
            $properties[$property->getName()] = PropertyMeta::from($property, $property->getAttributes(Locked::class) !== [], $file, $url, $property->getAttributes(Remember::class) !== []);

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

        $view = $reflection->getAttributes(View::class)[0] ?? null;
        $layout = $reflection->getAttributes(Layout::class)[0] ?? null;
        $title = $reflection->getAttributes(Title::class)[0] ?? null;
        $lazy = ($reflection->getAttributes(LazyLoad::class)[0] ?? null)?->newInstance();

        if (! $page && ($layout !== null || $title !== null)) {
            throw new CompileException("#[Layout] and #[Title] are a page's: {$class} is a component (it renders inside a page). Move it to the pages namespace (fusion.pages) to route it.", $file);
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
            $view?->newInstance()->name ?? $defaultView,
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
        );
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
     * The parameters of a #[Server] method: values from the browser (scalars and arrays) or
     * services the container provides (class types).
     */
    private static function parameters(ReflectionMethod $method, string $file): array
    {
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type !== null && ! $type instanceof ReflectionNamedType) {
                throw new CompileException("#[Server] {$method->getName()}(): union and intersection parameter types are not supported", $file, $method->getStartLine());
            }

            if ($parameter->isPassedByReference()) {
                throw new CompileException("#[Server] {$method->getName()}(): parameters cannot be passed by reference", $file, $method->getStartLine());
            }

            $parameters[] = [
                'name' => $parameter->getName(),
                'type' => $type?->getName(),
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
        return array_keys(array_filter($this->properties, fn (PropertyMeta $p) => $p->locked));
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
