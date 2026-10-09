<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\Computed;
use Nitro\Attributes\Server;
use Nitro\Attributes\Validate;
use Nitro\Attributes\View;
use Nitro\Component;

/** A component with a Solid view, reading it from its props: a browser method, a #[Computed] value, a #[Server] method with its status and errors. */
#[View(solid: 'solid-counter')]
class SolidCounter extends Component
{
    public int $count = 0;

    #[Validate('required|min:3')]
    public string $title = '';

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
        /** Long enough for the view to show save.processing. */
        usleep(200_000);
        $this->validate();

        return "saved {$this->title} at {$this->count}";
    }
}
