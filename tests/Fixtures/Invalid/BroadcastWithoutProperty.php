<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\On;
use Nitro\Fusion\Component;

/** A broadcast channel that reads {missing}: no such property. */
class BroadcastWithoutProperty extends Component
{
    public int $count = 0;

    #[On('echo-private:orders.{missing},OrderShipped')]
    public function shipped(array $event): void
    {
        $this->count++;
    }
}
