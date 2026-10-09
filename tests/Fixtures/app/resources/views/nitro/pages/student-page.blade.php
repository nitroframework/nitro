<article class="student">
    <h1>{{ $student->name }}</h1>
    <p class="email">{{ $student->email }}</p>
    <p class="shout">{{ $this->shout() }}</p>
    <input type="text" nitro:model="note">
    <p class="note">Note: {{ $note }}</p>
    <button nitro:click="rename('Renamed')">Rename</button>
</article>
