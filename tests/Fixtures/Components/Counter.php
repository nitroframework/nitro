<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

class Counter extends Component
{
    use Doubles;

    public int $count = 0;

    public int $step = 1;

    public array $history = [];

    public function increment(): void
    {
        $this->count += $this->step;
        $this->history[] = $this->count;
    }

    public function decrement(): void
    {
        $this->count -= $this->step;
        $this->history[] = $this->count;
    }

    #[Server]
    public function save(): int
    {
        Store::$saved['counter'] = $this->count;
        $this->dispatch('counter-saved', $this->count);

        return $this->count;
    }
}
