<?php

namespace Nitro\Fusion\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nitro\Fusion\Facades\Fusion;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\Fixtures\Livewire\Ticker;

/**
 * The fixture app's own provider: registers the Livewire component used next to Fusion, and the
 * data shared with every component (read when a page or call needs it: Greeting renames it).
 */
class FixtureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('ticker', Ticker::class);

        Fusion::share('user', fn () => ['name' => Store::$saved['user'] ?? 'Ada']);
    }
}
