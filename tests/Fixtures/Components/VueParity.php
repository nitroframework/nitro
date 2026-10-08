<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** The parity component (Parity) with its Vue view. */
#[View(vue: 'vue-parity')]
class VueParity extends Component
{
    use Parity;
}
