<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

/**
 * Partial reloads ($reload, $this->reload()): a controller page (RenderController::orders())
 * whose props come from the session.
 */
class Orders extends Component
{
    public array $orders = [];

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
