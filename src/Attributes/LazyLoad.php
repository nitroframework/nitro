<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * Leave the component out of the main bundle (app.js), as Viewi's #[LazyLoad]: the browser loads
 * its compiled code, a chunk of its own, when a page first needs it. Components that share a
 * group share a chunk; the default group is the component's name.
 *
 * A routed page's chunk loads while a fusion:navigate visit fetches the page's data, and a page
 * rendered on the server lists the chunks it uses in @fusionScripts: neither waits for the other.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class LazyLoad
{
    public function __construct(public readonly string $group = '')
    {
    }
}
