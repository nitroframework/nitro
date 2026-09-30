<section class="journal">
    <p class="status" fusion:ref="status">{{ $ready ? 'ready' : 'waiting' }}, {{ count($saved) }} saved</p>
    <input type="text" fusion:ref="note" fusion:model="note">
    <button type="button" fusion:click="save">Save</button>
    <a class="away" href="/counter" fusion:navigate>Away</a>
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
            <div class="journal-toast" fusion:ref="toast">
                <input type="text" class="toast-note" fusion:model="note">
                <button type="button" class="dismiss" fusion:click="$set('ready', false)">Dismiss</button>
            </div>
        @endteleport
    @endif
</section>
