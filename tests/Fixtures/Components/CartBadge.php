<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Stores\Cart;

/** Reads the cart store: its count and total, rendered again when the cart changes. */
class CartBadge extends Component
{
    public Cart $cart;
}
