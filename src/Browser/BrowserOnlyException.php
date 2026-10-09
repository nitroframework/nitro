<?php

namespace Nitro\Browser;

use LogicException;

/** Nitro::browser() was called on the server: it is compiled to JavaScript and runs in the browser only. */
final class BrowserOnlyException extends LogicException
{
    public static function make(): self
    {
        return new self('Nitro::browser() runs in the browser: call it from a method that runs there, not from mount(), a #[Server] method or a controller.');
    }
}
