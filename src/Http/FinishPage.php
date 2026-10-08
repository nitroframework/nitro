<?php

namespace Nitro\Fusion\Http;

use Closure;
use Illuminate\Http\Request;
use Nitro\Fusion\Rendering\PageData;
use Nitro\Fusion\Rendering\Portals;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a page's render can only finish once all of it is rendered: the content teleported to each
 * @portal (Rendering\Portals), and the page's data block (Rendering\PageData). In any HTML answer
 * of the web group.
 */
final class FinishPage
{
    public function __construct(private readonly Portals $portals, private readonly PageData $pageData)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response && ! $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            $content = $response->getContent();

            if (is_string($content)) {
                $finished = $this->pageData->finish(str_contains($content, 'id="portal_') ? $this->portals->fill($content) : $content);

                if ($finished !== $content) {
                    $response->setContent($finished);
                }
            }
        }

        return $response;
    }
}
