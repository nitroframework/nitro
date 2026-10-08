<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Attributes\Validate;
use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** A component with a Vue view (<script setup> and useFusion()): a browser method, a #[Computed] value, a #[Server] method with its status and errors. */
#[View(vue: 'vue-counter')]
class VueCounter extends Component
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
