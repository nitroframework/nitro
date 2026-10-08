<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Layout;
use Nitro\Fusion\Component;

/** A page in a layout nested in the app's (components.layouts.nested): a visit from the app changes only the app's main area. */
#[Layout('components.layouts.nested')]
class Nested extends Component
{
    public int $taps = 0;

    public function tap(): void
    {
        $this->taps++;
    }
}
