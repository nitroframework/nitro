<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\On;
use Nitro\Component;

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
