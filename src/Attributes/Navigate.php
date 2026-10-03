<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * How a fusion:navigate visit shows this page, in place of fusion.navigation:
 *
 *   'data'    the browser waits for the page's data, then renders it complete
 *   'markup'  the browser renders the page at once with its defaults; its properties count as
 *             deferred until the data arrives, so @deferred / @placeholder show placeholders
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Navigate
{
    public function __construct(public readonly string $mode)
    {
    }
}
