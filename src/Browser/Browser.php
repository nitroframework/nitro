<?php

namespace Nitro\Fusion\Browser;

/**
 * The browser's APIs, from a component's browser methods: Fusion::browser()->clipboard()->write($text).
 *
 * The compiler turns each call into the matching JavaScript (js/functions/browser/_browser_*.js); a bundle
 * has only the APIs its components use. These classes describe the API for the compiler, editors
 * and static analysis: called on the server, they throw.
 */
final class Browser
{
    /** The system clipboard. */
    public function clipboard(): Clipboard
    {
        throw BrowserOnlyException::make();
    }

    /** localStorage: kept across visits. */
    public function storage(): Storage
    {
        throw BrowserOnlyException::make();
    }

    /** sessionStorage: kept while the tab is open. */
    public function session(): Storage
    {
        throw BrowserOnlyException::make();
    }

    /** The 2D context of the component's <canvas fusion:ref="$ref">, drawn on once the component has rendered. */
    public function canvas(string $ref): Canvas
    {
        throw BrowserOnlyException::make();
    }

    /** A WebSocket connection, one per URL and component; closed when the component leaves the page. */
    public function socket(string $url): Socket
    {
        throw BrowserOnlyException::make();
    }
}
