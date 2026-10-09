<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\Computed;
use Nitro\Attributes\Server;
use Nitro\Attributes\View;
use Nitro\Component;

/**
 * A component whose view is JavaScript (the "plain" engine, which the layout registers): its
 * state, a browser method, a #[Computed] value and a #[Server] method, reached through the handle.
 */
#[View('plain-counter', engine: 'plain')]
class PlainCounter extends Component
{
    public int $count = 0;

    public string $label = 'Clicks';

    #[Computed]
    public function doubled(): int
    {
        return $this->count * 2;
    }

    public function increment(int $by = 1): void
    {
        $this->count += $by;
    }

    #[Server]
    public function save(): string
    {
        Store::$saved['plain'] = $this->count;

        return "saved {$this->count}";
    }
}
