<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * An array property that takes files from the browser (<input type="file" multiple>): a list of
 * Uploads. Only these and Upload properties accept uploads; any other array refuses them.
 *
 *   #[Uploads]
 *   public array $photos = [];
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Uploads
{
}
