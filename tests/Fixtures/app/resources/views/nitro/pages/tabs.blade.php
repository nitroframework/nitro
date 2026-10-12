<section class="tabs">
    @foreach (['one', 'two', 'three'] as $name)
        <button type="button" class="tab-{{ $name }}" nitro:click="$set('tab', $name)">{{ $name }}</button>
    @endforeach
    <p class="shown">{{ $tab }}</p>
</section>
