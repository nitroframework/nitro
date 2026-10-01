<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\Modelable;
use Nitro\Fusion\Attributes\On;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Pages\Shelf;

/**
 * A child bound with fusion:model (#[Modelable]), dispatching the Livewire ways: ->to() a
 * component, ->self(), from the browser and from the server; $parent in its view.
 */
class SearchBox extends Component
{
    #[Modelable]
    public string $value = '';

    public int $pings = 0;

    public function clear(): void
    {
        $this->value = '';
    }

    public function announce(): void
    {
        $this->dispatch('noted', $this->value)->to(Shelf::class);
    }

    public function ping(): void
    {
        $this->dispatch('noted', 'ping')->self();
    }

    #[On('noted')]
    public function pinged(): void
    {
        $this->pings++;
    }

    #[Server]
    public function announceFromServer(): void
    {
        $this->dispatch('noted', 'from the server')->to('pages::shelf');
    }
}
