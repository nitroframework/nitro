<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Component;

/** A chart a library would draw: its view's @nitroScript draws it, and draws it again as its points change. */
class Sparkline extends Component
{
    /** @var list<int> */
    public array $points = [1, 2];

    public function add(): void
    {
        $this->points[] = 1;
    }
}
