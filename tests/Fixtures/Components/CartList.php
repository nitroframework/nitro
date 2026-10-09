<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Component;
use Nitro\Tests\Fixtures\Stores\Cart;

/** Lists the cart store's items; removes and saves through the store, from its view. */
class CartList extends Component
{
    public Cart $cart;
}
