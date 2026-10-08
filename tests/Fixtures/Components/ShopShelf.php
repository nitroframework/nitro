<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Stores\Cart;

/** Changes the cart store through its methods, in the browser and on the server. */
class ShopShelf extends Component
{
    public Cart $cart;

    public array $products = [['name' => 'Pen', 'price' => 2], ['name' => 'Book', 'price' => 10]];

    public function buy(int $index): void
    {
        $this->cart->add($this->products[$index]['name'], $this->products[$index]['price']);
    }

    /** A computed value of the component, from the store: worked out again when the cart changes. */
    #[Computed]
    public function inCart(): int
    {
        return $this->cart->count;
    }

    /** A server method of the component reading the store, as the browser sent it, and changing it. */
    #[Server]
    public function checkout(): void
    {
        Store::$saved['checkout'] = [$this->cart->count, $this->cart->total];
        $this->cart->add('Receipt', 0);
    }
}
