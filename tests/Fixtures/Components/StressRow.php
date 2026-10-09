<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Component;

/** A row of the stress page: props from the page, an event back up to it. */
class StressRow extends Component
{
    public array $order = [];

    public bool $selected = false;

    public string $focus = '';

    public function pick(): void
    {
        $this->dispatch('row-picked', id: $this->order['id']);
    }
}
