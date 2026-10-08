<?php

namespace Nitro\Fusion\Http;

use Closure;
use Illuminate\Http\Request;
use Nitro\Fusion\Rendering\Portals;
use Symfony\Component\HttpFoundation\Response;

/**
 * Viewi's portals post action, for any HTML response (a view with @fusion embeds, not only a
 * Route::fusion page, which fills its portals itself): each @portal gets the content teleported to it.
 */
final class FillPortals
{
    public function __construct(private readonly Portals $portals)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response && ! $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            $content = $response->getContent();

            if (is_string($content) && str_contains($content, 'id="portal_')) {
                $response->setContent($this->portals->fill($content));
            }
        }

        return $response;
    }
}
