<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Remember;
use Nitro\Fusion\Component;

/** #[Remember] on a property the browser cannot change. */
class RememberedLocked extends Component
{
    #[Locked]
    #[Remember]
    public int $total = 0;
}
