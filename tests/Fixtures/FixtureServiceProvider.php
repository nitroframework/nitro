<?php

namespace Nitro\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Nitro\Tests\Fixtures\Livewire\Ticker;

/**
 * The fixture app's own provider: registers the Livewire component used next to Nitro, in the
 * app with Livewire (bootstrap/livewire.php).
 */
class FixtureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->getProviders(LivewireServiceProvider::class) !== []) {
            Livewire::component('ticker', Ticker::class);
        }
    }
}
