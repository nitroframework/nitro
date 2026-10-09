<section class="shop-shelf">
    @foreach ($products as $i => $product)
        <button type="button" class="buy" nitro:click="buy($i)">{{ $product['name'] }}</button>
    @endforeach
    <button type="button" class="buy-direct" nitro:click="$cart->add('Gift', 5)">Gift</button>
    <span class="in-cart">{{ $this->inCart }}</span>
    <button type="button" class="checkout" nitro:click="checkout">Checkout</button>
</section>
