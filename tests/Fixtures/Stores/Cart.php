<?php

namespace Nitro\Fusion\Tests\Fixtures\Stores;

use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Persist;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Attributes\Sync;
use Nitro\Fusion\Attributes\Validate;
use Nitro\Fusion\Store;
use Nitro\Fusion\Tests\Fixtures\Components\Store as Record;

/**
 * A store: the cart the shop's components share (CartBadge, ShopShelf, CartList), across visits
 * between /shop and /shop/cart. #[Persist]: its items outlast a reload (madeBy, #[Locked], is the
 * server's); #[Sync]: they follow in the other tabs (the browser tests' "store plugins").
 */
#[Persist]
class Cart extends Store
{
    #[Validate(['items' => 'array|max:20', 'items.*.name' => 'required|string|max:40', 'items.*.price' => 'required|integer|min:0'])]
    #[Sync]
    public array $items = [];

    /** Set on the server only: where the cart was made. */
    #[Locked]
    public string $madeBy = '';

    public function mount(): void
    {
        $this->items = session('fixture-cart', []);
        $this->madeBy = 'server';
        Record::$log[] = 'cart mounted';
    }

    public function add(string $name, int $price): void
    {
        $this->items[] = ['name' => $name, 'price' => $price];
    }

    public function remove(int $index): void
    {
        array_splice($this->items, $index, 1);
    }

    #[Computed]
    public function count(): int
    {
        return count($this->items);
    }

    #[Computed]
    public function total(): int
    {
        return array_sum(array_column($this->items, 'price'));
    }

    #[Server]
    public function save(): void
    {
        session(['fixture-cart' => $this->items]);
        Record::$saved['cart'] = $this->items;
    }
}
