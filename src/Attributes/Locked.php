<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * A property the browser can read but never change: the server rejects any update to it, and
 * the compiler rejects fusion:model on it. Model properties are always locked.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Locked
{
}
