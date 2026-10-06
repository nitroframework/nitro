<?php

namespace Nitro\Fusion\Build;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Fusion;

/**
 * The application's Route::fusion() pages, as the bundle tells the browser about them: each one's
 * path as a JavaScript pattern, so a visit knows the page (and its chunk) before the server answers.
 * A part of the Bundle.
 */
final class PageRoutes
{
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

            $routes[] = [$this->pattern($route->uri()), $pages[$class], $route];
        }

        return $routes;
    }

    /**
     * A client page's routes must need nothing from the server either: no middleware beyond the
     * web group, and no parameters to bind.
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
            $problem = match (true) {
                $route->parameterNames() !== [] => 'route parameters ({'.implode('}, {', $route->parameterNames()).'})',
                $extra !== [] => 'middleware beyond the web group ('.implode(', ', array_map(fn ($m) => is_string($m) ? $m : get_debug_type($m), $extra)).')',
                default => null,
            };

            if ($problem !== null) {
                throw new CompileException("#[Navigate('client')] on {$meta->class}: its route /".ltrim($route->uri(), '/')." has {$problem}, which only the server can check. Use #[Navigate('markup')].", $meta->file);
            }
        }
    }

    /** A route's path as a JavaScript regular expression: each {parameter} one path segment. */
    private function pattern(string $uri): string
    {
        $pattern = '';

        foreach (explode('/', trim($uri, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            $pattern .= match (true) {
                preg_match('/^\{\w+\?\}$/', $segment) === 1 => '(?:/[^/]+)?',
                preg_match('/^\{\w+\}$/', $segment) === 1 => '/[^/]+',
                default => '/'.preg_replace_callback('/\{\w+\??\}|[^{]+/', fn (array $m) => $m[0][0] === '{' ? '[^/]+' : preg_quote($m[0], '#'), $segment),
            };
        }

        return '^'.($pattern === '' ? '/' : $pattern).'/?$';
    }
}
