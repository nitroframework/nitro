<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Livewire\LivewireServiceProvider;
use Nitro\NitroServiceProvider;
use Nitro\Tests\Fixtures\FixtureServiceProvider;
use Nitro\Tests\Fixtures\Http\ShareFixtureData;

/**
 * The fixture application on stock Laravel, as most apps have it: Nitro without Livewire, whose
 * boot makes the HTTP kernel early and so hides what an app without it meets. bootstrap/livewire.php
 * sets $withLivewire for the app with both, whose views compile apart: Livewire changes what they
 * compile to, as it would in an app that has it.
 */
$views = dirname(__DIR__).'/storage/framework/'.(($withLivewire ?? false) ? 'views-livewire' : 'views');
@mkdir($views, 0777, true);
putenv("VIEW_COMPILED_PATH={$views}");
$_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $views;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withProviders([...(($withLivewire ?? false) ? [LivewireServiceProvider::class] : []), NitroServiceProvider::class, FixtureServiceProvider::class])
    /** The data shared with every component, set for each request (Nitro is created per request). */
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: ShareFixtureData::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
