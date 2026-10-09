<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Livewire\LivewireServiceProvider;
use Nitro\NitroServiceProvider;
use Nitro\Tests\Fixtures\FixtureServiceProvider;

/** The fixture application on stock Laravel. */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withProviders([LivewireServiceProvider::class, NitroServiceProvider::class, FixtureServiceProvider::class])
    ->withMiddleware(function (Middleware $middleware): void {})
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
