<?php

namespace Nitro\Fusion\Attributes;

use Attribute;
use Nitro\Fusion\NavigationMode;

/**
 * How a fusion:navigate visit shows this page, in place of fusion.navigation: a NavigationMode,
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
