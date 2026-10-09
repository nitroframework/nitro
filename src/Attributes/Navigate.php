<?php

namespace Nitro\Attributes;

use Attribute;
use Nitro\NavigationMode;

/**
 * How a nitro:navigate visit shows this page, in place of nitro.navigation: a NavigationMode,
 * or its value ('data', 'markup', 'client'). What each means is NavigationMode's.
 *
 *   #[Navigate('markup')]
 *   #[Navigate(NavigationMode::Client)]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Navigate
{
    public function __construct(public readonly NavigationMode|string $mode)
    {
    }
}
