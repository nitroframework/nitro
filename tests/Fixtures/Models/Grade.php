<?php

namespace Nitro\Fusion\Tests\Fixtures\Models;

/**
 * A backed enum, as a #[Server] parameter takes one: from its value.
 */
enum Grade: string
{
    case Seven = '7';
    case Eight = '8';
}
