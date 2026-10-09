<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\LazyLoad;
use Nitro\Component;

/** An island in a chunk of its own: loaded when the island starts, not with the page. */
#[LazyLoad('islands')]
class Beacon extends Component
{
    public int $hits = 0;

    public string $text = '';

    public function hit(): void
    {
        $this->hits++;
    }
}
