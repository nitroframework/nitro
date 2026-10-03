<?php

namespace Nitro\Fusion\Http;

use Closure;
use Illuminate\Http\Request;
use Nitro\Fusion\Fusion;
use Symfony\Component\HttpFoundation\Response;

/**
 * A redirect answering a view page's $put, $patch or $delete (a controller's `return back()`) is
 * a 303, as Inertia's middleware makes it: the browser follows a 302 with the same method, so a
 * DELETE would land on the page's GET route as a DELETE; a 303 is followed with a GET. In the web
 * group (FusionServiceProvider), for Fusion's requests only (X-Fusion-Navigate).
 */
final class SeeOther
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 302 && $request->headers->has(Fusion::NAVIGATE_HEADER)
            && in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)) {
            $response->setStatusCode(303);
        }

        return $response;
    }
}
