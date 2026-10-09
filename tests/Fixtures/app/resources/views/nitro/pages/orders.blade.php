<div class="orders">
    <ul class="list">
        @foreach ($orders as $order)
            <li>{{ $order }}</li>
        @endforeach
    </ul>
    <p class="total">{{ $total }}</p>
    <input class="note" nitro:model="note">
    <p class="note-shown">{{ $note }}</p>
    <button class="add" nitro:click="add('tea')">Add</button>
    <button class="add-all" nitro:click="addAndReloadAll('cake')">Add, reload all</button>
    <button class="reload-total" nitro:click="$reload('total')">Total</button>
    <button class="refresh-total" nitro:click="refreshTotal">Total (method)</button>
</div>
