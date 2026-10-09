<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\Modelable;
use Nitro\Component;

/** A quantity bound with nitro:model on its tag (#[Modelable]): the stress page binds it to a nested path. */
class QtyInput extends Component
{
    #[Modelable]
    public int $value = 1;

    public function more(): void
    {
        $this->value++;
    }

    public function less(): void
    {
        $this->value = max(1, $this->value - 1);
    }
}
