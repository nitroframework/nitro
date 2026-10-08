<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Remember;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;
use Nitro\Fusion\Facades\Fusion;
use Nitro\Fusion\Tests\Fixtures\Components\Store;

/**
 * Fusion::share()'s data (FixtureServiceProvider shares the user): in the view, in a browser
 * method, and after a #[Server] call that changes it.
 */
class Greeting extends Component
{
    public string $shouted = '';

    /** Kept in the history entry: back brings it back. */
    #[Remember]
    public string $draft = '';

    public function shout(): void
    {
        $this->shouted = strtoupper($this->shared('user.name', 'guest'));
    }

    #[Server]
    public function rename(string $name): void
    {
        Store::$saved['user'] = $name;
    }

    /** Fusion::flash(): with this call's answer. */
    #[Server]
    public function celebrate(): void
    {
        Fusion::flash('toast', 'Well done');
    }

    /** Fusion::flash() then a redirect: the flash data goes with the page it goes to. */
    #[Server]
    public function celebrateElsewhere(): void
    {
        Fusion::flash('toast', 'Over here');
        $this->redirect('/counter', navigate: true);
    }
}
