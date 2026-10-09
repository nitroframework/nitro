<?php

namespace Nitro;

/**
 * State several components share, for longer than any one of them lives: a cart a badge, a list
 * of products and a cart page all show. Written as a component is, without a view:
 *
 *   class Cart extends Store
 *   {
 *       public array $items = [];
 *
 *       public function mount(): void { $this->items = session('cart', []); }   // server, first page load
 *       public function add(array $product): void { $this->items[] = $product; } // browser
 *
 *       #[Computed]
 *       public function count(): int { return count($this->items); }
 *
 *       #[Server]
 *       public function save(): void { session(['cart' => $this->items]); }      // server
 *   }
 *
 * A component uses it through a property of its type, `public Cart $cart;`: Nitro gives each
 * component the same store, one per request on the server and one per browser tab, where it lasts
 * across nitro:navigate visits. A change made through its methods renders again every component
 * that reads it. Its properties are not the component's state, and the component changes them
 * through the store's methods only.
 *
 * Classes in App\Nitro\Stores are named "stores::cart"; a store is never placed in a view or
 * routed.
 */
abstract class Store extends Component
{
}
