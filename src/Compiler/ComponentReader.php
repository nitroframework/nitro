<?php

namespace Nitro\Compiler;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Nitro\Attributes\Cached;
use Nitro\Attributes\Computed;
use Nitro\Attributes\Description;
use Nitro\Attributes\Html;
use Nitro\Attributes\Layout;
use Nitro\Attributes\LazyLoad;
use Nitro\Attributes\Locked;
use Nitro\Attributes\Modelable;
use Nitro\Attributes\Navigate;
use Nitro\Attributes\On;
use Nitro\Attributes\Persist;
use Nitro\Attributes\Remember;
use Nitro\Attributes\Server;
use Nitro\Attributes\Sync;
use Nitro\Attributes\Title;
use Nitro\Attributes\Uploads;
use Nitro\Attributes\Url;
use Nitro\Attributes\Validate;
use Nitro\Attributes\View;
use Nitro\Component;
use Nitro\FeatureAttribute;
use Nitro\Support\ComponentHooks;
use Nitro\Support\PropertyHooks;
use Nitro\NavigationMode;
use Nitro\Store;
use Nitro\Support\Naming;
use Nitro\Support\Protocol;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reads a component class once: its properties, methods, attributes and view, checked as they are
 * read (what cannot work fails the build, where it is written). ComponentMeta holds what it finds.
 */
final class ComponentReader
{
    public static function read(string $class, string $name, string $defaultView, bool $page = false): ComponentMeta
    {
        try {
            return self::readClass($class, $name, $defaultView, $page);
        } catch (\Error $exception) {
            /**
             * The class's own code failing as it is read: an attribute PHP cannot make (repeated,
             * on the wrong target, wrong arguments), a default value naming a constant that is not
             * there. Where it is, as any compile error. An error in Nitro's own code is not this.
             */
            $attribute = array_filter($exception->getTrace(), fn (array $frame) => ($frame['class'] ?? null) === \ReflectionAttribute::class && $frame['function'] === 'newInstance') !== [];

            if ($attribute || ! str_starts_with(realpath($exception->getFile()) ?: $exception->getFile(), dirname(__DIR__))) {
                throw new CompileException("{$class}: {$exception->getMessage()}", (string) (new ReflectionClass($class))->getFileName());
            }

            throw $exception;
        }
    }

    private static function readClass(string $class, string $name, string $defaultView, bool $page): ComponentMeta
    {
        $reflection = new ReflectionClass($class);
        $file = (string) $reflection->getFileName();

        if (! $reflection->isSubclassOf(Component::class) || ! $reflection->isInstantiable()) {
            throw new CompileException("{$class} is not a Nitro component (a concrete class extending ".Component::class.')', $file);
        }

        $properties = [];
        $modelable = null;
        $rules = [];
        $stores = StateProperties::stores($class);
        $isStore = $reflection->isSubclassOf(Store::class);

        if ($isStore) {
            self::assertStore($reflection, $stores, $file);
        }

        foreach (StateProperties::of($class) as $property) {
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
        $html = [];
        $cached = [];

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

            if (($keep = $method->getAttributes(Cached::class)[0] ?? null) !== null) {
                $cached[$methodName] = self::cached($keep, $method, $isStore, array_keys($properties), $file);
            }

            if ($method->getAttributes(Computed::class) !== []) {
                $computed[] = self::computed($method, $properties, $file);
            }

            /** #[Html]: runs on the server only, so it is never compiled for the browser. */
            if ($method->getAttributes(Html::class) !== []) {
                $html[] = self::html($method, $properties, $isStore, $file);

                continue;
            }

            /** An attribute that works as a feature runs around a #[Server] call: on a browser method, it would never run. */
            $handler = $method->getAttributes(FeatureAttribute::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

            if ($handler !== null && $method->getAttributes(Server::class) === []) {
                throw new CompileException('#['.class_basename($handler->getName())."] on {$methodName}(): it runs around a #[Server] method's call, and {$methodName}() runs in the browser; mark it #[Server]", $file, $method->getStartLine());
            }

            if ($method->getAttributes(Server::class) !== []) {
                if (! $method->isPublic() || ComponentHooks::isReserved($methodName)) {
                    throw new CompileException("#[Server] {$methodName}() must be a public method that is not one Nitro calls on the server itself (".implode(', ', ComponentHooks::SERVER).')', $file, $method->getStartLine());
                }

                $why = ComponentHooks::whyNotServer($methodName) ?? PropertyHooks::whyNotServer($methodName, array_keys($properties));

                if ($why !== null) {
                    throw new CompileException("#[Server] on {$why}", $file, $method->getStartLine());
                }

                $server[] = $methodName;
                $parameters[$methodName] = self::parameters($method, $file);

                continue;
            }

            if (! ComponentHooks::isReserved($methodName)) {
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
            throw new CompileException("#[Layout], #[Title], #[Description] and #[Navigate] are a page's: {$class} is a component (it renders inside a page). Move it to the pages namespace (nitro.pages) to route it.", $file);
        }

        if (is_string($navigate)) {
            $navigate = NavigationMode::tryFrom($navigate) ?? throw new CompileException("#[Navigate] on {$class} is ".NavigationMode::listed().", not '{$navigate}'", $file);
        }

        /** A client page renders in the browser without asking the server: it must need nothing from it. */
        if ($navigate === NavigationMode::Client) {
            $needs = array_filter([
                $reflection->hasMethod(ComponentHooks::MOUNT) ? 'mount()' : null,
                $server !== [] ? '#[Server] methods ('.implode(', ', $server).')' : null,
                array_filter($properties, fn (PropertyMeta $property) => $property->url !== null) !== [] ? '#[Url] properties' : null,
            ]);

            if ($needs !== []) {
                throw new CompileException("#[Navigate('client')] on {$class}: a client page renders without asking the server, so it cannot have ".implode(', ', $needs).". Use #[Navigate('markup')]: it renders at once too, and its data follows.", $file);
            }
        }

        /** A page is a chunk of its own unless it names a group or #[LazyLoad(false)]; a component only with #[LazyLoad]. */
        $group = match (true) {
            $lazy === null => $page ? Naming::group($name) : null,
            $lazy->group === false => null,
            default => $lazy->group ?: Naming::group($name),
        };

        if (is_string($group) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $group) !== 1) {
            throw new CompileException("#[LazyLoad] group [{$group}] of {$class} is not a valid chunk name (letters, digits, dots, dashes and underscores)", $file);
        }

        return new ComponentMeta(
            $class,
            $name,
            $file,
            $view->name ?? $defaultView,
            $properties,
            $server,
            $browser,
            $listeners,
            $reflection->hasMethod(ComponentHooks::MOUNT),
            $layout?->newInstance(),
            $title?->newInstance()->title,
            $reflection->getConstants(),
            $parameters,
            $view->engine ?? Protocol::bladeEngine(),
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
            $cached,
            $html,
        );
    }

    /**
     * An #[Html] method: read as a property, so it takes nothing; run on the server only, so it is
     * not also #[Server] or #[Computed], nor a lifecycle hook; and it gives a string of HTML.
     *
     * @param  array<string, PropertyMeta>  $properties
     */
    private static function html(ReflectionMethod $method, array $properties, bool $isStore, string $file): string
    {
        $name = $method->getName();
        $returns = $method->getReturnType();
        $problem = match (true) {
            $isStore => 'is for a view, and a store has none: put it on the component that shows it',
            ! $method->isPublic() => 'must be public',
            $method->getAttributes(Server::class) !== [] || $method->getAttributes(Computed::class) !== [] => 'is #[Html] only: it runs on the server already, and is read as $this->'.$name,
            ComponentHooks::isReserved($name) || ComponentHooks::isBrowserHook($name) || PropertyHooks::parse($name, array_keys($properties)) !== null => 'cannot be a lifecycle hook',
            $method->getNumberOfRequiredParameters() > 0 => 'cannot take parameters: it is read as $this->'.$name,
            isset($properties[$name]) => 'has the name of a public property',
            ! $returns instanceof ReflectionNamedType || $returns->getName() !== 'string' => "must return string: declare it, {$name}(): string",
            default => null,
        };

        if ($problem !== null) {
            throw new CompileException("#[Html] {$name}() {$problem}", $file, $method->getStartLine());
        }

        return $name;
    }

    /**
     * #[Cached] on a method: mount() (the state it leaves) or a #[Server] method (what it returns),
     * on a page or a component. A browser method runs where there is no cache to keep it in.
     *
     * @param  ReflectionAttribute<Cached>  $attribute
     * @param  list<string>  $properties  the component's
     */
    private static function cached(ReflectionAttribute $attribute, ReflectionMethod $method, bool $isStore, array $properties, string $file): Cached
    {
        $name = $method->getName();

        if ($isStore) {
            throw new CompileException("#[Cached] on {$name}(): a store's state is made for each request and shared by its components; keep what it loads with #[Cached] on a component's mount() or #[Server] method", $file, $method->getStartLine());
        }

        if ($name !== ComponentHooks::MOUNT && $method->getAttributes(Server::class) === []) {
            throw new CompileException("#[Cached] on {$name}(): it keeps what mount() or a #[Server] method gives, and {$name}() runs in the browser; mark it #[Server], or load the data in mount()", $file, $method->getStartLine());
        }

        try {
            $rule = $attribute->newInstance();
        } catch (\InvalidArgumentException $exception) {
            throw new CompileException("{$name}(): {$exception->getMessage()}", $file, $method->getStartLine());
        }

        foreach ($rule->by ?? [] as $property) {
            if (! in_array($property, $properties, true)) {
                throw new CompileException("#[Cached] on {$name}(): it depends on \${$property}, which is not a public property of the component", $file, $method->getStartLine());
            }
        }

        return $rule;
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
            ComponentHooks::isReserved($name) || ComponentHooks::isBrowserHook($name) || PropertyHooks::parse($name, array_keys($properties)) !== null => 'cannot be a lifecycle hook',
            $method->getNumberOfRequiredParameters() > 0 => 'cannot take parameters: it is read as $this->'.$name,
            isset($properties[$name]) => 'has the name of a public property',
            default => null,
        };

        if ($problem !== null) {
            throw new CompileException("#[Computed] {$name}() {$problem}", $file, $method->getStartLine());
        }

        return $name;
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

    /**
     * What a store cannot have: what belongs to a component on the page (events it listens to, the
     * query string, the history entry, nitro:model on its tag, uploads), or stores of its own.
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

        foreach (StateProperties::of($class) as $property) {
            foreach ([Url::class => '#[Url]', Remember::class => '#[Remember]', Modelable::class => '#[Modelable]', Uploads::class => '#[Uploads]'] as $attribute => $label) {
                if ($property->getAttributes($attribute) !== []) {
                    throw new CompileException("{$label} is for components: \${$property->getName()} of the store {$class} cannot have it", $file);
                }
            }
        }
    }
}
