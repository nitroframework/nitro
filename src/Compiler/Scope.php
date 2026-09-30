<?php

namespace Nitro\Fusion\Compiler;

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
    ) {
    }

    /** The same scope, knowing the application's components by class. */
    public function withComponentNames(Closure $componentName): self
    {
        return new self($this->properties, $this->clientMethods, $this->serverMethods, $this->variable, $this->file, $this->lineOffset, $this->constants, $this->locked, $this->computed, $componentName);
    }

    /** A component's name from its class, null when the class is not a component. */
    public function componentName(string $class): ?string
    {
        return $this->componentName === null ? null : ($this->componentName)($class);
    }

    public function withVariables(Closure $variable, ?string $file = null, int $lineOffset = 0): self
    {
        return new self($this->properties, $this->clientMethods, $this->serverMethods, $variable, $file ?? $this->file, $lineOffset, $this->constants, $this->locked, $this->computed, $this->componentName);
    }

    /** The same members, for a method in $file (no template variables). */
    public function forFile(string $file): self
    {
        return new self($this->properties, $this->clientMethods, $this->serverMethods, null, $file, 0, $this->constants, $this->locked, $this->computed, $this->componentName);
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
        return in_array($name, $this->properties, true);
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
