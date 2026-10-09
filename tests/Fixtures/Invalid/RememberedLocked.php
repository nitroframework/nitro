<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Locked;
use Nitro\Attributes\Remember;
use Nitro\Component;

/** #[Remember] on a property the browser cannot change. */
class RememberedLocked extends Component
{
    #[Locked]
    #[Remember]
    public int $total = 0;
}
