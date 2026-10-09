<div class="transitions">
    <button class="toggle" nitro:click="toggle">Toggle</button>
    @if ($open)
        <div class="panel" nitro:transition.duration.200ms>Panel</div>
    @endif

    <button class="toggle-named" nitro:click="toggleNamed">Toggle named</button>
    @if ($shown)
        <p class="named" nitro:transition="fade">Named</p>
    @endif

    <button class="add" nitro:click="add">Add</button>
    <button class="remove" nitro:click="removeFirst">Remove</button>
    <ul class="items">
        @foreach ($items as $item)
            <li nitro:key="item-{{ $item }}" nitro:transition.opacity.duration.150ms>{{ $item }}</li>
        @endforeach
    </ul>

    <button class="reverse" nitro:click="reverseMovers">Reverse</button>
    <button class="drop" nitro:click="dropMover">Drop</button>
    <ul class="moving">
        @foreach ($movers as $mover)
            <li class="mover" nitro:key="mover-{{ $mover }}" nitro:transition.opacity.move.duration.150ms>{{ $mover }}</li>
        @endforeach
    </ul>

    <button class="switch-tab" nitro:click="switchTab">Tab</button>
    <div class="tabs">
        @if ($tab === 'a')
            <div class="tab-a" nitro:transition.out-in.duration.100ms>A</div>
        @else
            <div class="tab-b" nitro:transition.out-in.duration.100ms>B</div>
        @endif
    </div>

    <button class="switch-pane" nitro:click="switchPane">Pane</button>
    <div class="panes">
        @if ($pane === 'x')
            <p class="pane-x" nitro:transition.in-out.duration.100ms>X</p>
        @else
            <p class="pane-y" nitro:transition.in-out.duration.100ms>Y</p>
        @endif
    </div>
</div>
