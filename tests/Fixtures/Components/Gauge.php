<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Component;
use RuntimeException;

/** A component that fails when told to, without a fallback of its own: the application's, or Fusion's. */
class Gauge extends Component
{
    public function mount(bool $fail = false): void
    {
        if ($fail) {
            throw new RuntimeException('The gauge cannot be read.');
        }
    }
}
