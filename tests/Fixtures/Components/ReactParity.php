<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** The parity component (Parity) with its React view. */
#[View(react: 'react-parity')]
class ReactParity extends Component
{
    use Parity;
}
