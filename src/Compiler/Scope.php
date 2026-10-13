<?php

namespace Nitro\Compiler;

use Closure;

/**
 * What a piece of code may refer to while it is compiled.
 *
 *  - $this members: the component's properties and its client and server methods
 *  - how a bare $variable resolves (a local, or in a template a component property)
 */
final class Scope
{
    /**
     * @param  list<string>  $properties  public properties of the component
     * @param  list<string>  $clientMethods  methods compiled to JS
     * @param  list<string>  $serverMethods  #[Server] methods, called through the endpoint
     * @param  Closure(string): ?string|null  $variable  resolves a variable that is not a local
     *                                                   (templates: properties, $errors, $loop)
     */
    public function __construct(
        public readonly array $properties = [],
        public readonly array $clientMethods = [],
        public readonly array $serverMethods = [],
        public readonly ?Closure $variable = null,
        public readonly ?string $file = null,
        public readonly int $lineOffset = 0,
        /** @var array<string, mixed> class constants (self::X / static::X) */
        public readonly array $constants = [],
        /** @var list<string> #[Locked] properties: readable, never written from the browser */
        public readonly array $locked = [],
        /** @var list<string> #[Computed] methods, read as $this->name */
        public readonly array $computed = [],
        /** @var (Closure(string): ?string)|null a component class to its name (dispatch()->to(Card::class)) */
        public readonly ?Closure $componentName = null,
        /** The names the browser sees, in an obfuscated build (null: the names themselves) */
        public readonly ?Obfuscator $names = null,
        /** @var array<string, array{name: string, scope: Scope}> the stores it uses, by property: the store's name and members */
        public readonly array $stores = [],
        /** @var list<string> a Blade component's view's inputs: read as $this->name, by their own names in an obfuscated build; never a path */
        public readonly array $inputs = [],
    ) {
    }

    /**
     * The same scope, with the stores it uses (public Cart $cart): by property, the store's name
     * and its own scope (its properties, methods, #[Computed]).
     *
     * @param  array<string, array{name: string, scope: Scope}>  $stores
     */
    public function withStores(array $stores): self
    {
        return new self($this->properties, $this->clientMethods, $this->serverMethods, $this->variable, $this->file, $this->lineOffset, $this->constants, $this->locked, $this->computed, $this->componentName, $this->names, $stores, $this->inputs);
    }

    /** Whether $name is a property holding a store. */
    public function isStore(string $name): bool
    {
        return isset($this->stores[$name]);
    }

    /** The store a property holds: its scope, by the browser's names. */
    public function store(string $name): Scope
    {
        return $this->stores[$name]['scope']->withNames($this->names);
    }

    /** The same scope, writing the browser's names of methods and components (an obfuscated build). */
    public function withNames(?Obfuscator $names): self
    {
        return new self($this->properties, $this->clientMethods, $this->serverMethods, $this->variable, $this->file, $this->lineOffset, $this->constants, $this->locked, $this->computed, $this->componentName, $names, $this->stores, $this->inputs);
    }

    /** A method's name in the browser. */
    public function browserMethod(string $name): string
    {
        return $this->names?->method($name) ?? $name;
    }

    /** A #[Browser] class's name in the browser. */
    public function browserClass(string $class): string
    {
        return $this->names?->browserClass($class) ?? $class;
    }

    /** A property's name in the browser. */
    public function browserProperty(string $name): string
    {
        return in_array($name, $this->inputs, true) ? $name : ($this->names?->property($name) ?? $name);
    }

    /** A property path ("form.email") in the browser: its property by the browser's name. */
    public function browserPath(string $path): string
    {
        $root = explode('.', $path, 2)[0];

        return $this->names !== null && in_array($root, $this->properties, true) ? $this->names->path($path) : $path;
    }

    /** A component's or page's name in the browser. */
    public function browserComponent(string $name): string
    {
        return $this->names?->component($name) ?? $name;
    }

    /** The same scope, knowing the application's components by class. */
    public function withComponentNames(Closure $componentName): self
    {
        return new self($this->properties, $this->clientMethods, $this->serverMethods, $this->variable, $this->file, $this->lineOffset, $this->constants, $this->locked, $this->computed, $componentName, $this->names, $this->stores, $this->inputs);
    }

    /** A component's name from its class, null when the class is not a component. */
    public function componentName(string $class): ?string
    {
        return $this->componentName === null ? null : ($this->componentName)($class);
    }

    public function withVariables(Closure $variable, ?string $file = null, int $lineOffset = 0): self
    {
        return new self($this->properties, $this->clientMethods, $this->serverMethods, $variable, $file ?? $this->file, $lineOffset, $this->constants, $this->locked, $this->computed, $this->componentName, $this->names, $this->stores, $this->inputs);
    }

    /** The same members, for a method in $file (no template variables). */
    public function forFile(string $file): self
    {
        return new self($this->properties, $this->clientMethods, $this->serverMethods, null, $file, 0, $this->constants, $this->locked, $this->computed, $this->componentName, $this->names, $this->stores, $this->inputs);
    }

    public function isComputed(string $name): bool
    {
        return in_array($name, $this->computed, true);
    }

    public function isLocked(string $name): bool
    {
        return in_array($name, $this->locked, true);
    }

    public function isProperty(string $name): bool
    {
        return in_array($name, $this->properties, true) || in_array($name, $this->inputs, true);
    }

    public function isClientMethod(string $name): bool
    {
        return in_array($name, $this->clientMethods, true);
    }

    public function isServerMethod(string $name): bool
    {
        return in_array($name, $this->serverMethods, true);
    }
}
