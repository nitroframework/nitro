<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Layout;
use Nitro\Component;

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
