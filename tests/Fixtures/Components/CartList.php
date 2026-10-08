<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Stores\Cart;

/** Lists the cart store's items; removes and saves through the store, from its view. */
class CartList extends Component
{
    public Cart $cart;
}
