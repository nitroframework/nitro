<div class="orders">
    <ul class="list">
        @foreach ($orders as $order)
            <li>{{ $order }}</li>
        @endforeach
    </ul>
    <p class="total">{{ $total }}</p>
    <input class="note" fusion:model="note">
    <p class="note-shown">{{ $note }}</p>
    <button class="add" fusion:click="add('tea')">Add</button>
    <button class="add-all" fusion:click="addAndReloadAll('cake')">Add, reload all</button>
    <button class="reload-total" fusion:click="$reload('total')">Total</button>
    <button class="refresh-total" fusion:click="refreshTotal">Total (method)</button>
</div>
