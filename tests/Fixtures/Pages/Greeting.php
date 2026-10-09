<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Remember;
use Nitro\Attributes\Server;
use Nitro\Component;
use Nitro\Facades\Nitro;
use Nitro\Tests\Fixtures\Components\Store;

/**
 * Nitro::share()'s data (FixtureServiceProvider shares the user): in the view, in a browser
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

    /** Nitro::flash(): with this call's answer. */
    #[Server]
    public function celebrate(): void
    {
        Nitro::flash('toast', 'Well done');
    }

    /** Nitro::flash() then a redirect: the flash data goes with the page it goes to. */
    #[Server]
    public function celebrateElsewhere(): void
    {
        Nitro::flash('toast', 'Over here');
        $this->redirect('/counter', navigate: true);
    }
}
