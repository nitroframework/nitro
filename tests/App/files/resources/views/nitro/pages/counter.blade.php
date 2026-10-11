<div>
    <button nitro:click="increment">Add one</button>
    <span class="count">{{ $count }}</span>
    <button nitro:click="save">Save</button>

    @teleport('dock')
        <p class="saved">Count: {{ $count }}</p>
    @endteleport
</div>
