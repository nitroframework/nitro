<?php

namespace Nitro\Browser;

use Closure;

/**
 * A WebSocket connection. Nitro::browser()->socket($url) returns the component's connection to
 * the URL, opening it the first time; a relative URL is on the page's host. It reconnects when
 * the connection drops, and closes when the component leaves the page.
 *
 * Messages that are JSON arrive decoded (objects as arrays), others as strings; send() encodes
 * arrays as JSON. What is sent before the connection opens goes once it does.
 */
final class Socket
{
    /** $then($data) for each message. One handler: a second call replaces it. */
    public function onMessage(Closure $then): static
    {
        throw BrowserOnlyException::make();
    }

    /** $then() each time the connection opens. */
    public function onOpen(Closure $then): static
    {
        throw BrowserOnlyException::make();
    }

    /** $then() each time the connection closes. */
    public function onClose(Closure $then): static
    {
        throw BrowserOnlyException::make();
    }

    public function send(mixed $data): static
    {
        throw BrowserOnlyException::make();
    }

    /** Close it for good: no reconnecting. */
    public function close(): void
    {
        throw BrowserOnlyException::make();
    }
}
