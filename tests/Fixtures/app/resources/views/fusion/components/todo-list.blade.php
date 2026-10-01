<section class="todos">
    <form fusion:submit.prevent="add">
        @csrf
        <input type="text" fusion:model="title" placeholder="What needs doing?">
        <button type="submit" @disabled(trim($title) === '')>Add</button>
    </form>
    @error('todos') <p class="error">{{ $message }}</p> @enderror
    @error('todos.*.title')
        <p class="error">{{ $message }}</p>
    @enderror
    {{-- count() of a Countable ($errors, Laravel's MessageBag): every message, as PHP 8 counts it. --}}
    @if (count($errors) > 0)
        <p class="error-count">{{ count($errors) }} to fix</p>
    @endif
    <nav>
        @foreach (['all' => 'All', 'open' => 'Open', 'done' => 'Done'] as $value => $label)
            <button @class(['active' => $filter === $value]) fusion:click="$set('filter', $value)">{{ $label }}</button>
        @endforeach
    </nav>
    <ul>
        @forelse ($this->visible() as $todo)
            <fusion:todo-item :todo="$todo" :key="$todo['id']" />
        @empty
            <li class="empty">Nothing here.</li>
        @endforelse
    </ul>
    <p class="remaining">{{ $this->remaining() }} {{ $this->remaining() === 1 ? 'item' : 'items' }} left</p>
    <button fusion:click="persist">Save all</button>
</section>
