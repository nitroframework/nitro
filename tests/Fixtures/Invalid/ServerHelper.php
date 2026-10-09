<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Browser;

/** A #[Browser] class with a method that needs the server: it cannot compile. */
#[Browser]
final class ServerHelper
{
    public static function stamp(): string
    {
        return now()->toTimeString();
    }
}
