<section class="journal">
    <p class="status" nitro:ref="status">{{ $ready ? 'ready' : 'waiting' }}, {{ count($saved) }} saved</p>
    <input type="text" nitro:ref="note" nitro:model="note">
    <button type="button" nitro:click="save">Save</button>
    <a class="away" href="/counter" nitro:navigate>Away</a>
    @teleport('dock')
        <aside class="journal-dock">
            <span class="dock-note">{{ $note }}</span>
            @foreach ($saved as $entry)
                <em>{{ $entry }}</em>
            @endforeach
        </aside>
    @endteleport
    @if ($ready)
        @teleport('toasts')
            <div class="journal-toast" nitro:ref="toast">
                <input type="text" class="toast-note" nitro:model="note">
                <button type="button" class="dismiss" nitro:click="$set('ready', false)">Dismiss</button>
            </div>
        @endteleport
    @endif
</section>
