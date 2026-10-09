<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * Leave a component out of the main bundle (app.js), as Viewi's #[LazyLoad]: the browser loads
 * its compiled code, a chunk of its own, when a page first needs it. Components that share a
 * group share a chunk; the default group is the component's name.
 *
 * Pages are chunks of their own without it: #[LazyLoad('group')] puts a page in a group's chunk
 * instead, #[LazyLoad(false)] keeps it in app.js.
 *
 * A routed page's chunk loads while a nitro:navigate visit fetches the page's data, and a page
 * rendered on the server lists the chunks it uses in @nitroScripts: neither waits for the other.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class LazyLoad
{
    public function __construct(public readonly string|false $group = '')
    {
    }
}
