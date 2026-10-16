<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Server;
use Nitro\Attributes\Title;
use Nitro\Attributes\View;
use Nitro\Component;

/** A page whose own view is Svelte: routed, its data from mount(), a browser method and a #[Server] one. */
#[View(svelte: 'svelte-page')]
#[Title('Svelte page')]
class SveltePage extends Component
{
    public string $greeting = '';

    public int $knocks = 0;

    public function mount(): void
    {
        $this->greeting = 'Hello from mount()';
        $this->knocks = 1;
    }

    public function knock(): void
    {
        $this->knocks++;
    }

    #[Server]
    public function shout(): string
    {
        return strtoupper($this->greeting);
    }
}
