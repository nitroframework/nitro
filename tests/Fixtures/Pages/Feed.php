<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

/**
 * fusion:visible: a list that loads more as its end comes into view, a .once marker, and a
 * #[Server] method that changes nothing (called once per time it comes into view).
 */
class Feed extends Component
{
    public array $items = [1, 2, 3, 4, 5];

    public int $seen = 0;

    public int $pings = 0;

    public function loadMore(): void
    {
        $last = count($this->items);

        foreach (range($last + 1, $last + 5) as $item) {
            $this->items[] = $item;
        }
    }

    public function markSeen(): void
    {
        $this->seen++;
    }

    #[Server]
    public function ping(): void
    {
        session()->put('pings', session('pings', 0) + 1);
    }

    #[Server]
    public function countPings(): void
    {
        $this->pings = session('pings', 0);
    }
}
