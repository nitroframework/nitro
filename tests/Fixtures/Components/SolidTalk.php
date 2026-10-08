<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** The events component (Talk) with its Solid view. */
#[View(solid: 'solid-talk')]
class SolidTalk extends Component
{
    use Talk;
}
