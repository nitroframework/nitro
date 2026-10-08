<section class="shop-shelf">
    @foreach ($products as $i => $product)
        <button type="button" class="buy" fusion:click="buy($i)">{{ $product['name'] }}</button>
    @endforeach
    <button type="button" class="buy-direct" fusion:click="$cart->add('Gift', 5)">Gift</button>
    <span class="in-cart">{{ $this->inCart }}</span>
    <button type="button" class="checkout" fusion:click="checkout">Checkout</button>
</section>
