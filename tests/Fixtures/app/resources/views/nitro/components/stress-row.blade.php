@use('Nitro\Tests\Fixtures\Browser\Money')
<li @class(['row', 'selected' => $selected, 'focus' => $order['status'] === $focus]) nitro:tooltip="{{ $order['customer'] }} ({{ count($order['lines']) }})">
    <button type="button" class="pick" nitro:click="pick">{{ $order['customer'] }}</button>
    @switch($order['status'])
        @case('paid')
            <x-badge tone="green">Paid</x-badge>
            @break
        @case('shipped')
            <x-badge tone="blue" class="shipped">Shipped</x-badge>
            @break
        @default
            <x-badge />
    @endswitch
    <ul class="lines">
        @forelse ($order['lines'] as $j => $line)
            @if ($line['qty'] > 99)
                <li class="bulk">{{ $loop->iteration }}/{{ $loop->count }} bulk: {{ $line['name'] }}</li>
                @break
            @endif
            <li class="line">{{ $line['name'] }} × {{ $line['qty'] }} = {{ Money::format($line['price'] * $line['qty']) }}</li>
        @empty
            <li class="none">No lines</li>
        @endforelse
    </ul>
    @include('partials.heading', ['title' => 'Order '.$order['id'], 'level' => 3])
</li>
