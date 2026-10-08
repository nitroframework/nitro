<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** The events component (Talk) with its Vue view. */
#[View(vue: 'vue-talk')]
class VueTalk extends Component
{
    use Talk;
}
