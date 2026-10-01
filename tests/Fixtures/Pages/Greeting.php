<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Components\Store;

/**
 * Fusion::share()'s data (FixtureServiceProvider shares the user): in the view, in a browser
 * method, and after a #[Server] call that changes it.
 */
class Greeting extends Component
{
    public string $shouted = '';

    public function shout(): void
    {
        $this->shouted = strtoupper($this->shared('user.name', 'guest'));
    }

    #[Server]
    public function rename(string $name): void
    {
        Store::$saved['user'] = $name;
    }
}
