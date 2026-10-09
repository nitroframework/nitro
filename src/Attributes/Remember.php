<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * A property kept in the browser's history entry, as Inertia's useRemember: back and forward,
 * and a reload, bring back the value it had there (a half-filled form), instead of the one the
 * page starts with.
 *
 *   #[Remember] public string $message = '';
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Remember
{
}
