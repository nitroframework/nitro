<?php

namespace Nitro\Tests\Fixtures;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Nitro\Tests\Fixtures\Livewire\Ticker;

/**
 * The fixture app's own provider: registers the Livewire component used next to Nitro, in the
 * app with Livewire (bootstrap/livewire.php), and shares a value with every view, as apps do.
 */
class FixtureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::share('siteName', 'Nitro');

        if ($this->app->getProviders(LivewireServiceProvider::class) !== []) {
            Livewire::component('ticker', Ticker::class);
        }
    }
}
