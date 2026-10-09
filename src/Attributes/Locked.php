<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * A property the browser can read but never change: the server rejects any update to it, and
 * the compiler rejects nitro:model on it. Model properties are always locked.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Locked
{
}
