<?php

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Nitro\Foundation\Application;
use Livewire\LivewireServiceProvider;
use Nitro\Fusion\FusionServiceProvider;
use Nitro\Fusion\Tests\Fixtures\FixtureServiceProvider;

/** The same fixture application on Nitro. */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withProviders([LivewireServiceProvider::class, FusionServiceProvider::class, FixtureServiceProvider::class])
    ->withMiddleware(function (Middleware $middleware): void {})
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
