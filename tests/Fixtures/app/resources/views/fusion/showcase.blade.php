<section class="showcase">
    @include('partials.heading', ['title' => 'Cakes '.count($cakes), 'level' => 2])
    <ul>
        @foreach ($cakes as $cake)
            @include('partials.cake-row', ['cake' => $cake, 'index' => $loop->index])
        @endforeach
    </ul>
    @includeWhen($featured, 'partials.heading', ['title' => 'Featured', 'level' => 3])
    @includeIf('partials.missing')
    <x-badge :tone="$tone" class="extra" id="main-badge" data-count="{{ count($cakes) }}">{{ $note ?: 'New' }}</x-badge>
    <x-badge tone="red" />
    <x-card title="Hello &amp; welcome" :shadow="$featured">
        <x-slot:footer>
            <span class="foot">{{ $clicks }} clicks</span>
        </x-slot>
        Body text for {{ $tone }}
        <x-badge :tone="$tone">inner</x-badge>
    </x-card>
    <x-button fusion:click="click" class="big" :disabled="$clicks > 2">Click</x-button>
    <x-button variant="link" fusion:click="feature">Feature</x-button>
    <p class="clicks">{{ $clicks }}</p>
</section>
