<?php

namespace Nitro\Fusion\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nitro\Fusion\Tests\Fixtures\Livewire\Ticker;

/**
 * The fixture app's own provider: registers the Livewire component used next to Fusion.
 */
class FixtureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('ticker', Ticker::class);
    }
}
