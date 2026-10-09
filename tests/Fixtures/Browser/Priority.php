<?php

namespace Nitro\Tests\Fixtures\Browser;

use Nitro\Attributes\Browser;

/** A #[Browser] int-backed enum. */
#[Browser]
enum Priority: int
{
    case Low = 1;
    case Normal = 2;
    case High = 3;
}
