<?php

namespace Nitro\Fusion\Tests\Fixtures\Livewire;

use Livewire\Component;

/**
 * A real Livewire component, on the same pages as Fusion components (coexistence tests).
 */
class Ticker extends Component
{
    public int $ticks = 0;

    public function tick(): void
    {
        $this->ticks++;
    }

    public function render()
    {
        return view('livewire.ticker');
    }
}
