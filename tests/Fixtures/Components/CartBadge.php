<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Component;
use Nitro\Tests\Fixtures\Stores\Cart;

/** Reads the cart store: its count and total, rendered again when the cart changes. */
class CartBadge extends Component
{
    public Cart $cart;
}
