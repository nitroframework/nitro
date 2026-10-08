<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** The events component (Talk) with its React view. */
#[View(react: 'react-talk')]
class ReactTalk extends Component
{
    use Talk;
}
