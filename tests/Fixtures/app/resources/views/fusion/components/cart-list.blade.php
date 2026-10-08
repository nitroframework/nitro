<section class="cart-list">
    <ul>
        @forelse ($cart->items as $i => $item)
            <li class="item">{{ $item['name'] }} <button type="button" class="remove" fusion:click="$cart->remove($i)">x</button></li>
        @empty
            <li class="empty">Empty</li>
        @endforelse
    </ul>
    <button type="button" class="save" fusion:click="$cart->save()">Save</button>
</section>
