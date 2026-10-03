{{-- A page that is only a view (ViewPages): NotesController renders it and takes its actions. --}}
@page(
    title: 'Notes',
    props: ['notes' => [], 'count' => 0],
    state: ['form' => ['title' => ''], 'filter' => '', 'open' => false],
    remember: ['form'],
    url: ['filter'],
)
<div class="notes">
    <form fusion:submit.prevent="$post(route('notes.store'), $form, ['reset' => ['form']])">
        <input class="title" fusion:model="form.title">
        @error('title') <p class="error">{{ $message }}</p> @enderror
        <button class="add">Add</button>
    </form>
    <input class="filter" fusion:model="filter">
    <button class="toggle" fusion:click="$toggle('open')">{{ $open ? 'Open' : 'Closed' }}</button>
    <button class="go" fusion:click="$visit(route('counter'))">Counter</button>
    <p class="count">{{ $count }}</p>
    <ul>
        @foreach ($notes as $i => $note)
            @if ($filter === '' || str_contains($note, $filter))
                <li fusion:key="note-{{ $i }}">
                    <span>{{ $note }}</span>
                    <button class="remove" fusion:click="$delete(route('notes.destroy', $i))">x</button>
                </li>
            @endif
        @endforeach
    </ul>
</div>
