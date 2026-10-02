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
</div>
