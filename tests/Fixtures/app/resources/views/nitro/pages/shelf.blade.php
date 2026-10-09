<main class="shelf">
    <nitro:card title="Stock">
        <p class="count">{{ $label }}: {{ $count }}</p>
        <input type="text" class="label" nitro:model="label">
        <ul>
            @foreach ($items as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
        <nitro:todo-item :todo="['id' => 9, 'title' => $label, 'done' => false]" />
        <nitro:slot name="footer"><button type="button" class="add" nitro:click="add">Add</button></nitro:slot>
    </nitro:card>
    <nitro:card title="Empty" />
    <nitro:search-box nitro:model="query" />
    <p class="query">{{ $query }}</p>
    <button type="button" class="reset-query" nitro:click="$set('query', 'reset')">Reset</button>
    <button type="button" class="dispatch-to" nitro:click="$dispatchTo('pages::shelf', 'noted', 'via action')">Note</button>
    <ul class="notes">
        @foreach ($notes as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ul>
</main>
