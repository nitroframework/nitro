<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\View;
use Nitro\Component;

/** The events component (Talk) with its React view. */
#[View(react: 'react-talk')]
class ReactTalk extends Component
{
    use Talk;
}
