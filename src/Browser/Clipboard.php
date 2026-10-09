<?php

namespace Nitro\Browser;

use Closure;

/** The system clipboard (navigator.clipboard): on https pages and localhost. */
final class Clipboard
{
    /** Copy the text; $then() runs once it is copied. */
    public function write(string $text, ?Closure $then = null): void
    {
        throw BrowserOnlyException::make();
    }

    /** Read the clipboard's text: $then($text), once the visitor allows it. */
    public function read(Closure $then): void
    {
        throw BrowserOnlyException::make();
    }
}
