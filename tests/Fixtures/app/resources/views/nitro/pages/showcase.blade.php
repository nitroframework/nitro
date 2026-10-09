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
    <x-button nitro:click="click" class="big" :disabled="$clicks > 2">Click</x-button>
    <x-button variant="link" nitro:click="feature">Feature</x-button>
    <p class="clicks">{{ $clicks }}</p>
    @use('Nitro\Tests\Fixtures\Browser\{Money, OrderStatus}')
    @use('Nitro\Tests\Fixtures\Browser\Priority', 'Level')
    <p class="price">{{ Money::format($clicks * 1250) }}</p>
    <p class="levels">{{ count(Level::cases()) }} levels, {{ Level::High->name }}</p>
    <p class="basket">{{ $this->basket() }}</p>
    <select class="status" nitro:model="status">
        @foreach (OrderStatus::cases() as $case)
            <option value="{{ $case->value }}">{{ $case->label() }}</option>
        @endforeach
    </select>
    <p class="status-badge">{{ $status->badge() }}</p>
    <button type="button" class="advance" nitro:click="advance">Next</button>
    <button type="button" class="confirm" nitro:click="confirm">Confirm</button>
    <p class="seen">{{ $seen }}</p>
    @unless ($featured)
        <span class="tip" nitro:tooltip.top="{{ $clicks }} clicks">?</span>
    @endunless
    <span class="teller" nitro:tell="hello"></span>
    <p class="told">{{ $told }}</p>
</section>
