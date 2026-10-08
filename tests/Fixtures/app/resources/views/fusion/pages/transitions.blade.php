<div class="transitions">
    <button class="toggle" fusion:click="toggle">Toggle</button>
    @if ($open)
        <div class="panel" fusion:transition.duration.200ms>Panel</div>
    @endif

    <button class="toggle-named" fusion:click="toggleNamed">Toggle named</button>
    @if ($shown)
        <p class="named" fusion:transition="fade">Named</p>
    @endif

    <button class="add" fusion:click="add">Add</button>
    <button class="remove" fusion:click="removeFirst">Remove</button>
    <ul class="items">
        @foreach ($items as $item)
            <li fusion:key="item-{{ $item }}" fusion:transition.opacity.duration.150ms>{{ $item }}</li>
        @endforeach
    </ul>

    <button class="reverse" fusion:click="reverseMovers">Reverse</button>
    <button class="drop" fusion:click="dropMover">Drop</button>
    <ul class="moving">
        @foreach ($movers as $mover)
            <li class="mover" fusion:key="mover-{{ $mover }}" fusion:transition.opacity.move.duration.150ms>{{ $mover }}</li>
        @endforeach
    </ul>

    <button class="switch-tab" fusion:click="switchTab">Tab</button>
    <div class="tabs">
        @if ($tab === 'a')
            <div class="tab-a" fusion:transition.out-in.duration.100ms>A</div>
        @else
            <div class="tab-b" fusion:transition.out-in.duration.100ms>B</div>
        @endif
    </div>

    <button class="switch-pane" fusion:click="switchPane">Pane</button>
    <div class="panes">
        @if ($pane === 'x')
            <p class="pane-x" fusion:transition.in-out.duration.100ms>X</p>
        @else
            <p class="pane-y" fusion:transition.in-out.duration.100ms>Y</p>
        @endif
    </div>
</div>
