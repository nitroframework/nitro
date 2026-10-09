<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * The page title of a routed page, given to its layout as $title.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Title
{
    public function __construct(public readonly string $title)
    {
    }
}
