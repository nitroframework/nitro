<?php

namespace Nitro\Fusion\Build;

use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Support\Naming;

/**
 * The names the browser sees in an obfuscated build (fusion.build.obfuscate): methods, properties
 * and components under aliases, keyed by the application key (an HMAC: they cannot be worked back
 * by hashing guesses). The build writes them into the bundle; the server translates them back
 * where the browser hands them over (a #[Server] call's method, a snapshot, an update).
 *
 * A property path aliases its property only: the keys of an array property are its data. What
 * the runtime calls by name keeps its name: mounted(), destroyed(), updated(). Components keep
 * theirs when the application has a <fusion:dynamic-component>: the browser picks those by a
 * name computed as it runs. Pages are aliased either way.
 */
final class Obfuscator
{
    /** @var array<string, string>|null alias => component name */
    private ?array $components = null;

    /**
     * @param  list<string>  $names  the application's components and pages
     */
    public function __construct(
        private readonly string $key,
        private readonly array $names,
        private readonly bool $aliasComponents,
    ) {
    }

    /**
     * A method's name in the browser. updatedEmail() is "updated" and the property's alias (the
     * runtime calls updated<Property>() after a change); updated(), mounted(), destroyed() keep theirs.
     */
    public function method(string $name): string
    {
        if (preg_match('/^updated([A-Z]\w*)$/', $name, $m) === 1) {
            return 'updated'.ucfirst($this->property(lcfirst($m[1])));
        }

        return self::keepsName($name) ? $name : 'm'.substr(hash_hmac('sha256', 'method:'.$name, $this->key), 0, 7);
    }

    /** A property's name in the browser. */
    public function property(string $name): string
    {
        return 'p'.substr(hash_hmac('sha256', 'property:'.$name, $this->key), 0, 7);
    }

    /** A property path ("form.email") in the browser: its property by its alias, the keys under it as they are. */
    public function path(string $path): string
    {
        $parts = explode('.', $path, 2);

        return $this->property($parts[0]).(isset($parts[1]) ? '.'.$parts[1] : '');
    }

    /**
     * JavaScript for $this->js(), reading the component as $fusion.<name>: its properties and
     * methods by their aliases. Names built as it runs ($fusion[name]) cannot be: they keep theirs.
     *
     * @param  list<string>  $properties
     * @param  list<string>  $methods
     */
    public function script(string $code, array $properties, array $methods): string
    {
        return (string) preg_replace_callback('/\$fusion\.([A-Za-z_]\w*)/', fn (array $m) => '$fusion.'.match (true) {
            in_array($m[1], $properties, true) => $this->property($m[1]),
            in_array($m[1], $methods, true) => $this->method($m[1]),
            default => $m[1],
        }, $code);
    }

    /**
     * The property a name from the browser stands for, among the component's: by its alias, or by
     * its own name; null for none.
     *
     * @param  list<string>  $properties
     */
    public function propertyName(string $browserName, array $properties): ?string
    {
        foreach ($properties as $property) {
            if ($browserName === $property || $browserName === $this->property($property)) {
                return $property;
            }
        }

        return null;
    }

    /**
     * A property path from the browser ("p3a91f0c.email") as the component has it ("form.email").
     *
     * @param  list<string>  $properties
     */
    public function propertyPath(string $path, array $properties): string
    {
        $parts = explode('.', $path, 2);
        $root = $this->propertyName($parts[0], $properties) ?? $parts[0];

        return $root.(isset($parts[1]) ? '.'.$parts[1] : '');
    }

    /** A #[Browser] class's name in the browser (the browser never sends it back). */
    public function browserClass(string $class): string
    {
        return 'k'.substr(hash_hmac('sha256', 'class:'.$class, $this->key), 0, 9);
    }

    /** A component's or page's name in the browser. */
    public function component(string $name): string
    {
        if (! Naming::isPage($name) && ! $this->aliasComponents) {
            return $name;
        }

        return 'c'.substr(hash_hmac('sha256', 'component:'.$name, $this->key), 0, 9);
    }

    /** The component a name from the browser stands for: an alias, or a name kept as it is. */
    public function componentName(string $browserName): string
    {
        if ($this->components === null) {
            $this->components = [];

            foreach ($this->names as $name) {
                $this->components[$this->component($name)] = $name;
            }
        }

        return $this->components[$browserName] ?? $browserName;
    }

    /**
     * The method a name from the browser stands for, among the component's: by its alias, or
     * by its own name.
     *
     * @param  list<string>  $methods
     */
    public function methodName(string $browserName, array $methods): string
    {
        foreach ($methods as $method) {
            if ($browserName === $method || $browserName === $this->method($method)) {
                return $method;
            }
        }

        return $browserName;
    }

    /**
     * Fail the build when two names would share an alias (so unlikely it has not happened, but
     * the browser could not tell them apart).
     *
     * @param  list<string>  $names
     */
    public function assertDistinct(array $names, string $what): void
    {
        $seen = [];

        foreach ($names as $name) {
            $alias = match ($what) {
                'component' => $this->component($name),
                'property' => $this->property($name),
                default => $this->method($name),
            };

            if (isset($seen[$alias]) && $seen[$alias] !== $name) {
                throw new CompileException("Obfuscation gives {$seen[$alias]} and {$name} the same name in the browser: rename one.");
            }

            $seen[$alias] = $name;
        }
    }

    /** What the runtime calls by name: mounted(), destroyed(), updated(), updatedEmail(). */
    private static function keepsName(string $name): bool
    {
        return in_array($name, ['mounted', 'destroyed', 'updated'], true) || preg_match('/^updated[A-Z]/', $name) === 1;
    }
}
