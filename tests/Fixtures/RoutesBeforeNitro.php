<?php

namespace Nitro\Tests\Fixtures;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nitro\Tests\Fixtures\Pages\Counter;

/** A provider that boots before Nitro's and routes a page in its boot(), as a module package may. */
class RoutesBeforeNitro extends ServiceProvider
{
    public function boot(): void
    {
        Route::nitro('/early-counter', Counter::class)->name('early-counter');
    }
}
