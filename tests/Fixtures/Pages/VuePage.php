<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Layout;
use Nitro\Attributes\Server;
use Nitro\Attributes\Title;
use Nitro\Attributes\View;
use Nitro\Component;

/** A page whose own view is Vue: routed, its data from mount(), a deferred property, head() tags, a browser method and a #[Server] one. */
#[View(vue: 'vue-page')]
#[Title('Vue page')]
#[Layout('components.layouts.headed')]
class VuePage extends Component
{
    public string $greeting = '';

    public int $knocks = 0;

    public ?string $later = null;

    public function mount(): void
    {
        $this->greeting = 'Hello from mount()';
        $this->knocks = 1;
        $this->defer('later', fn () => 'Loaded after the page');
        $this->head()->description('A page whose view is Vue')->meta('framework', 'Vue');
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
