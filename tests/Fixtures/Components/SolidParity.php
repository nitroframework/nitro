<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** The parity component (Parity) with its Solid view. */
#[View(solid: 'solid-parity')]
class SolidParity extends Component
{
    use Parity;
}
