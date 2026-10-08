<main class="shelf">
    <fusion:card title="Stock">
        <p class="count">{{ $label }}: {{ $count }}</p>
        <input type="text" class="label" fusion:model="label">
        <ul>
            @foreach ($items as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
        <fusion:todo-item :todo="['id' => 9, 'title' => $label, 'done' => false]" />
        <fusion:slot name="footer"><button type="button" class="add" fusion:click="add">Add</button></fusion:slot>
    </fusion:card>
    <fusion:card title="Empty" />
    <fusion:search-box fusion:model="query" />
    <p class="query">{{ $query }}</p>
    <button type="button" class="reset-query" fusion:click="$set('query', 'reset')">Reset</button>
    <button type="button" class="dispatch-to" fusion:click="$dispatchTo('pages::shelf', 'noted', 'via action')">Note</button>
    <ul class="notes">
        @foreach ($notes as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ul>
</main>
