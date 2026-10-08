<?php

namespace Nitro\Fusion\Build;

use Nitro\Fusion\Compiler\Globals;
use Nitro\Fusion\Compiler\Obfuscator;
use Nitro\Fusion\Support\Protocol;

/**
 * What the browser calls components, methods and properties: an obfuscated build's aliases
 * (fusion.build.obfuscate, when the bundle it is served is one), or their own names. Names going
 * out are given as the browser has them; names coming back are read as what they stand for.
 */
final class BrowserNames
{
    private ?Obfuscator $obfuscator = null;

    private bool $resolved = false;

    public function __construct(private readonly Bundle $bundle)
    {
    }

    /** The obfuscated build's names, when the browser has one; else null (it has their own names). */
    public function obfuscator(): ?Obfuscator
    {
        if (! $this->resolved) {
            $this->obfuscator = $this->bundle->obfuscator();
            $this->resolved = true;
        }

        return $this->obfuscator;
    }

    /** A component's or page's name in the browser. */
    public function component(string $name): string
    {
        return $this->obfuscator()?->component($name) ?? $name;
    }

    /** The component a name from the browser stands for. */
    public function componentName(string $browserName): string
    {
        return $this->obfuscator()?->componentName($browserName) ?? $browserName;
    }

    /** A property's name in the browser. */
    public function property(string $name): string
    {
        return $this->obfuscator()?->property($name) ?? $name;
    }

    /**
     * $this->js() code, reading $fusion.<name> by the browser's names.
     *
     * @param  list<string>  $properties
     * @param  list<string>  $methods
     */
    public function script(string $code, array $properties, array $methods): string
    {
        return $this->obfuscator()?->script($code, $properties, $methods) ?? $code;
    }

    /**
     * The property a name from the browser stands for, among the component's.
     *
     * @param  list<string>  $properties
     */
    public function propertyName(string $browserName, array $properties): string
    {
        return $this->obfuscator()?->propertyName($browserName, $properties) ?? $browserName;
    }

    /**
     * A path from the browser ("pa1b2c3.email"): its property by its own name, the rest as it is.
     *
     * @param  list<string>  $properties
     */
    public function propertyPath(string $browserPath, array $properties): string
    {
        [$root, $rest] = array_pad(explode('.', $browserPath, 2), 2, null);

        return $this->propertyName($root, $properties).($rest === null ? '' : '.'.$rest);
    }

    /**
     * The method a name from the browser stands for, among the component's #[Server] methods.
     *
     * @param  list<string>  $methods
     */
    public function methodName(string $browserName, array $methods): string
    {
        return $this->obfuscator()?->methodName($browserName, $methods) ?? $browserName;
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
        $names = $this->obfuscator();

        if ($names === null || ! str_contains($html, Protocol::namespace())) {
            return $html;
        }

        $handlers = implode('|', array_map(fn (string $name) => preg_quote($name, '/'), [...Globals::EVENTS, ...array_keys(Globals::TRIGGERS)]));

        /** Each start tag (quoted values may hold ">"), then its fusion attributes. */
        $target = fn (string $name) => match (true) {
            in_array(trim($name), $methods, true) => $names->method(trim($name)),
            in_array(trim($name), $properties, true) => $names->property(trim($name)),
            default => trim($name),
        };
        $path = fn (string $path) => in_array(explode('.', $path, 2)[0], $properties, true) ? $names->path($path) : $path;

        return (string) preg_replace_callback('/<[a-zA-Z][^\s\/>]*(?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*\s*\/?>/', function (array $tag) use ($handlers, $target, $path) {
            if (! str_contains($tag[0], Protocol::namespace())) {
                return $tag[0];
            }

            $tag = (string) preg_replace('/(\sfusion:(?:'.$handlers.')(?:\.[\w\-.]+)?)\s*=\s*(?:"[^"]*"|\'[^\']*\')/', '$1=""', $tag[0]);
            $tag = (string) preg_replace_callback('/(\sfusion:model(?:\.[\w\-.]+)?\s*=\s*)(["\'])(.*?)\2/', fn (array $match) => $match[1].$match[2].$path($match[3]).$match[2], $tag);

            return (string) preg_replace_callback('/(\sfusion:target\s*=\s*)(["\'])(.*?)\2/', fn (array $match) => $match[1].$match[2].implode(',', array_map($target, explode(',', $match[3]))).$match[2], $tag);
        }, $html);
    }
}
