<?php

namespace Nitro\Fusion\Build;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\PropertyMeta;
use Nitro\Fusion\Fusion;

/**
 * The application's Route::fusion() pages, as the bundle tells the browser about them: each one's
 * path as a JavaScript pattern, so a visit knows the page (and its chunk) before the server answers.
 * A part of the Bundle.
 *
 * A route's parameters are named groups in its pattern, held to its where() constraints, so a
 * client page (#[Navigate('client')]) can take them from the URL as the server would: each one
 * into the page's property of the same name. A URL the browser cannot match goes to the server.
 */
final class PageRoutes
{
    /** The types a route parameter can fill on a client page: what a URL segment can be. */
    private const PARAMETER_TYPES = ['int', 'float', 'string'];

    public function __construct(
        private readonly Fusion $fusion,
        private readonly ?Router $router = null,
    ) {
    }

    /**
     * Every Route::fusion() page reached with GET, as [path pattern, page name, route] (routes on
     * a domain left out: their path alone does not say the page).
     *
     * @return list<array{0: string, 1: string, 2: Route}>
     */
    public function all(): array
    {
        if ($this->router === null) {
            return [];
        }

        $pages = [];

        foreach ($this->fusion->registry()->all() as $name => $class) {
            $pages[ltrim($class, '\\')] = $name;
        }

        $routes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $controller = $route->getAction('controller');
            $class = is_string($controller) ? ltrim(explode('@', $controller)[0], '\\') : null;

            if ($class === null || ! isset($pages[$class]) || $route->getDomain() !== null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $routes[] = [$this->pattern($route), $pages[$class], $route];
        }

        return $routes;
    }

    /**
     * A client page's routes must need nothing from the server either: no middleware beyond the
     * web group, and parameters the browser can take from the URL itself. Each one fills the
     * page's public property of the same name: a string, int or float (nullable when the
     * parameter is optional), not #[Locked] (the visitor writes the URL), not bound to a model,
     * and held to a where() constraint the browser can check too.
     *
     * @throws CompileException
     */
    public function assertClient(ComponentMeta $meta): void
    {
        $web = $this->router?->getMiddlewareGroups()['web'] ?? [];

        foreach ($this->all() as [, $name, $route]) {
            if ($name !== $meta->name) {
                continue;
            }

            $extra = array_values(array_filter($route->gatherMiddleware(), fn ($middleware) => $middleware !== 'web' && ! in_array($middleware, $web, true)));
            $problem = $extra !== []
                ? 'middleware beyond the web group ('.implode(', ', array_map(fn ($middleware) => is_string($middleware) ? $middleware : get_debug_type($middleware), $extra)).')'
                : $this->parameterProblem($meta, $route);

            if ($problem !== null) {
                throw new CompileException("#[Navigate('client')] on {$meta->class}: its route /".ltrim($route->uri(), '/')." has {$problem}, which only the server can check. Use #[Navigate('markup')].", $meta->file);
            }
        }
    }

    /**
     * What a client page's route parameters fill, for the browser: parameter => [the property's
     * browser name, its type]. Empty for a route without parameters.
     *
     * @return array<string, array{prop: string, type: string}>
     */
    public function clientParameters(ComponentMeta $meta, Route $route, ?Obfuscator $names = null): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $property = $meta->property($parameter);

            if ($property !== null) {
                $parameters[$parameter] = ['prop' => $names?->property($parameter) ?? $parameter, 'type' => $this->scalar($property) ?? 'string'];
            }
        }

        return $parameters;
    }

    /** What keeps a route parameter from filling a client page's property; null when nothing does. */
    private function parameterProblem(ComponentMeta $meta, Route $route): ?string
    {
        $optional = $this->optionalParameters($route);

        foreach ($route->parameterNames() as $parameter) {
            $property = $meta->property($parameter);
            $type = $property === null ? null : $this->scalar($property);

            $problem = match (true) {
                $route->bindingFieldFor($parameter) !== null => "a model bound by its {$route->bindingFieldFor($parameter)} ({{$parameter}:{$route->bindingFieldFor($parameter)}})",
                $this->router?->getBindingCallback($parameter) !== null => "a parameter bound to a model (Route::model() or Route::bind() for {{$parameter}})",
                $property === null => "the parameter {{$parameter}} and no public property \${$parameter} to take it",
                $property->locked => "the parameter {{$parameter}} for a #[Locked] property: the visitor writes the URL, so the browser sets it",
                $type === null => "the parameter {{$parameter}} for \${$parameter}, which is not a string, int or float",
                in_array($parameter, $optional, true) && ! $property->nullable && ! $property->hasDefault => "the optional parameter {{$parameter}} for \${$parameter}, which has no default and is not nullable",
                isset($route->wheres[$parameter]) && $this->javaScript($route->wheres[$parameter]) === null => "a where() constraint on {{$parameter}} the browser cannot check (/{$route->wheres[$parameter]}/)",
                default => null,
            };

            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /** The property's type when it is one a URL segment can fill: int, float or string. */
    private function scalar(PropertyMeta $property): ?string
    {
        $types = array_values(array_diff($property->types, ['null']));

        return $property->kind === PropertyMeta::VALUE && count($types) === 1 && in_array($types[0], self::PARAMETER_TYPES, true) ? $types[0] : null;
    }

    /** @return list<string> */
    private function optionalParameters(Route $route): array
    {
        preg_match_all('/\{(\w+)\?\}/', $route->uri(), $matches);

        return $matches[1];
    }

    /**
     * A route's path as a JavaScript regular expression: each {parameter} a named group, one path
     * segment, held to its where() constraint when the browser can check it.
     */
    private function pattern(Route $route): string
    {
        $pattern = '';

        foreach (explode('/', trim($route->uri(), '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            $pattern .= match (true) {
                preg_match('/^\{(\w+)\?\}$/', $segment, $match) === 1 => '(?:/(?<'.$match[1].'>'.$this->segment($route, $match[1]).'))?',
                preg_match('/^\{(\w+)\}$/', $segment, $match) === 1 => '/(?<'.$match[1].'>'.$this->segment($route, $match[1]).')',
                default => '/'.preg_replace_callback(
                    '/\{(\w+)\??\}|[^{]+/',
                    fn (array $match) => $match[0][0] === '{' ? '(?<'.$match[1].'>'.$this->segment($route, $match[1]).')' : preg_quote($match[0], '#'),
                    $segment,
                ),
            };
        }

        return '^'.($pattern === '' ? '/' : $pattern).'/?$';
    }

    /** What one parameter matches: its where() constraint, or any one path segment. */
    private function segment(Route $route, string $parameter): string
    {
        return isset($route->wheres[$parameter]) ? ($this->javaScript($route->wheres[$parameter]) ?? '[^/]+') : '[^/]+';
    }

    /**
     * A where() constraint as JavaScript reads it, or null when it uses what JavaScript's regular
     * expressions have no such thing as (or read otherwise): PCRE's anchors, possessive and atomic
     * groups, inline flags, named groups in its own syntax, Unicode properties.
     */
    private function javaScript(string $regex): ?string
    {
        /** \A \z \Z \G \h \H \R \K \X \p \P \Q \E; (?> (?| (?# (?P (?i)...; (?<name>; possessive *+ ++ ?+ }+. Lookarounds JavaScript has. */
        $foreign = '/\\\\[AzZGhHRKXpPQE]|\(\?(?:[>|#PiImsxUJ-]|<[A-Za-z])|[*+?}]\+/';

        if (preg_match($foreign, $regex) === 1) {
            return null;
        }

        /** No capturing groups of its own (an escaped \( is no group): the parameter's is the one the browser reads. */
        return '(?:'.preg_replace('/(?<!\\\\)\((?!\?)/', '(?:', $regex).')';
    }
}
