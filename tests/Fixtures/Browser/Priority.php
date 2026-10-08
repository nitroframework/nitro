<?php

namespace Nitro\Fusion\Tests\Fixtures\Browser;

use Nitro\Fusion\Attributes\Browser;

/** A #[Browser] int-backed enum. */
#[Browser]
enum Priority: int
{
    case Low = 1;
    case Normal = 2;
    case High = 3;
}
