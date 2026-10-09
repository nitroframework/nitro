<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\View;
use Nitro\Component;

/** The parity component (Parity) with its Solid view. */
#[View(solid: 'solid-parity')]
class SolidParity extends Component
{
    use Parity;
}
