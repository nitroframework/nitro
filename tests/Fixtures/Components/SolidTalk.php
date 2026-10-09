<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\View;
use Nitro\Component;

/** The events component (Talk) with its Solid view. */
#[View(solid: 'solid-talk')]
class SolidTalk extends Component
{
    use Talk;
}
