<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\View;
use Nitro\Component;

/** The parity component (Parity) with its Vue view. */
#[View(vue: 'vue-parity')]
class VueParity extends Component
{
    use Parity;
}
