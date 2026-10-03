<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * How a fusion:navigate visit shows this page, in place of fusion.navigation:
 *
 *   'data'    the browser waits for the page's data, then renders it complete
 *   'markup'  the browser renders the page at once with its defaults; its properties count as
 *             deferred until the data arrives, so @deferred / @placeholder show placeholders
 *   'client'  the browser renders the page without asking the server at all (its title and head
 *             worked out at build time): for pages that need nothing from it, with no mount(),
 *             #[Server] methods, #[Url] properties, route parameters or middleware beyond web
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Navigate
{
    public function __construct(public readonly string $mode)
    {
    }
}
