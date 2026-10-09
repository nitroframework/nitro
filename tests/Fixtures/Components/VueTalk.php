<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\View;
use Nitro\Component;

/** The events component (Talk) with its Vue view. */
#[View(vue: 'vue-talk')]
class VueTalk extends Component
{
    use Talk;
}
