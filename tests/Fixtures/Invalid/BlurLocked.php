<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Locked;
use Nitro\Attributes\Validate;
use Nitro\Component;

/** #[Validate(onBlur: true)] on a property the browser cannot change. */
class BlurLocked extends Component
{
    #[Locked]
    #[Validate('integer|min:0', onBlur: true)]
    public int $total = 0;
}
