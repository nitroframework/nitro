<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\View;
use Nitro\Component;

/** The parity component (Parity) with its React view. */
#[View(react: 'react-parity')]
class ReactParity extends Component
{
    use Parity;
}
