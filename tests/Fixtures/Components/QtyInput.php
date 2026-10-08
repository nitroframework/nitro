<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\Modelable;
use Nitro\Fusion\Component;

/** A quantity bound with fusion:model on its tag (#[Modelable]): the stress page binds it to a nested path. */
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
