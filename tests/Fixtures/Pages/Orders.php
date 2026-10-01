<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

/**
 * Partial reloads ($reload, $this->reload()): a controller page (RenderController::orders())
 * whose props come from the session.
 */
class Orders extends Component
{
    public array $orders = [];

    /** Locked: the browser cannot send it back, so a reload of it must reach the server signed. */
    #[Locked]
    public int $total = 0;

    public string $note = '';

    #[Server]
    public function add(string $item): void
    {
        session()->push('orders', $item);
        $this->reload('orders');
    }

    #[Server]
    public function addAndReloadAll(string $item): void
    {
        session()->push('orders', $item);
        $this->reload();
    }

    public function refreshTotal(): void
    {
        $this->reload('total');
    }
}
