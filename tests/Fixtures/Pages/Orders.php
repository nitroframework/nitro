<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Components\Store;

/**
 * Partial reloads ($reload, $this->reload()): a page whose properties come from the session.
 * mount() loads only what the request wants ($this->wants()), and logs what it loaded
 * (Store::$log).
 */
class Orders extends Component
{
    public array $orders = [];

    /** Locked: the browser cannot send it back, so a reload of it must reach the server signed. */
    #[Locked]
    public int $total = 0;

    public string $note = '';

    public function mount(): void
    {
        if ($this->wants('orders')) {
            Store::$log[] = 'orders';
            $this->orders = session('orders', []);
        }

        if ($this->wants('total')) {
            Store::$log[] = 'total';
            $this->total = count(session('orders', []));
        }
    }

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
