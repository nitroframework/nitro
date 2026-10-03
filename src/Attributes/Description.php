<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * A routed page's description, for search results and link previews (@fusionHead prints it).
 * $this->head()->description() in mount() sets one from data instead.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Description
{
    public function __construct(public readonly string $description)
    {
    }
}
