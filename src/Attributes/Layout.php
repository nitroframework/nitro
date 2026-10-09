<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * The Blade layout a routed page renders in; the page becomes its $slot.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Layout
{
    public function __construct(public readonly string $view, public readonly array $data = [])
    {
    }
}
