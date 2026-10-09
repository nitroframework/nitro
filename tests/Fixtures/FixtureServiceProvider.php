<?php

namespace Nitro\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nitro\Tests\Fixtures\Http\ShareFixtureData;
use Nitro\Tests\Fixtures\Livewire\Ticker;

/**
 * The fixture app's own provider: registers the Livewire component used next to Nitro, and the
 * middleware that shares data with every component (ShareFixtureData).
 */
class FixtureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('ticker', Ticker::class);

        /** Shared data is set for each request (Nitro is created per request). */
        $this->app->make('router')->pushMiddlewareToGroup('web', ShareFixtureData::class);
    }
}
