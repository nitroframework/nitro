<?php

namespace Nitro\Fusion\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A #[Server] call comes from a page: the page's authorization middleware (auth, can, verified,
 * ...) must still let the visitor in, or the call is refused. A snapshot kept from before a
 * logout, or taken from someone else's page, is useless.
 */
final class PersistentMiddleware
{
    /** @param  list<class-string>  $middleware  the middleware classes re-checked */
    public function __construct(private readonly Container $container, private readonly Router $router, private readonly array $middleware)
    {
    }

    /** @param  array{method?: string, uri?: string}  $page */
    public function check(array $page, Request $current): void
    {
        if (! isset($page['method'], $page['uri'])) {
            return;
        }

        $request = Request::create($page['uri'], $page['method'], [], $current->cookies->all(), [], $current->server->all());
        $request->setUserResolver($current->getUserResolver());

        if ($current->hasSession()) {
            $request->setLaravelSession($current->session());
        }

        try {
            $route = $this->router->getRoutes()->match($request);
        } catch (Throwable) {
            throw new HttpException(403, 'The page this component belongs to is gone.');
        }

        /** Route::fallback() answers any URL: the page is not there any more. */
        if ($route->isFallback) {
            throw new HttpException(403, 'The page this component belongs to is gone.');
        }

        $request->setRouteResolver(fn () => $route);

        $middleware = array_values(array_filter(
            $this->router->gatherRouteMiddleware($route),
            fn ($entry) => is_string($entry) && $this->rechecked(explode(':', $entry, 2)[0])
        ));

        if ($middleware === []) {
            return;
        }

        $allowed = new Response('allowed');

        try {
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);

            $result = (new Pipeline($this->container))
                ->send($request)
                ->through($middleware)
                ->then(fn () => $allowed);
        } catch (Throwable) {
            $result = null;
        }

        if ($result !== $allowed) {
            throw new HttpException(403, 'You no longer have access to the page this component belongs to.');
        }
    }

    /** A middleware class re-checked: one of the list, or extending one (an application's own Authenticate). */
    private function rechecked(string $class): bool
    {
        foreach ($this->middleware as $listed) {
            if ($class === $listed || is_a($class, $listed, true)) {
                return true;
            }
        }

        return false;
    }
}
