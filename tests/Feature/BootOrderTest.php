<?php

namespace Nitro\Tests\Feature;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Nitro\NitroServiceProvider;
use Nitro\Tests\Fixtures\RoutesBeforeNitro;
use Nitro\Tests\TestCase;

/**
 * Nitro does not rely on the order Laravel makes things in (ARCHITECTURE.md, "What Nitro
 * shares"): what it adds to Laravel is there for providers that boot before it too.
 */
final class BootOrderTest extends TestCase
{
    public function test_a_provider_booted_before_nitro_routes_pages(): void
    {
        $app = $this->app = Application::configure(basePath: self::APP)
            ->withProviders([RoutesBeforeNitro::class, NitroServiceProvider::class])
            ->create();
        $app->make(ConsoleKernel::class)->bootstrap();

        $routes = $app->make('router')->getRoutes();
        $routes->refreshNameLookups();

        $this->assertNotNull($routes->getByName('early-counter'), 'Route::nitro() in a boot() before Nitro\'s');
    }
}
