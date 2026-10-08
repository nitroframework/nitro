<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\LazyLoad;
use Nitro\Fusion\Component;

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
