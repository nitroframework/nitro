@use('Nitro\Fusion\Tests\Fixtures\Browser\OrderStatus')
<section class="stress">
    @include('partials.heading', ['title' => 'Orders ('.count($orders).')', 'level' => 2])
    <input class="filter" fusion:model.live="filter">
    <select class="sort" fusion:model="sort">
        <option value="id">Id</option>
        <option value="customer">Customer</option>
        <option value="total">Total</option>
    </select>
    <button type="button" class="advance" fusion:click="advance">Focus: {{ $focus->label() }}</button>
    <ol class="orders">
        @foreach ($this->visible as $order)
            <fusion:stress-row :order="$order" :selected="$selected === $order['id']" :focus="$focus->value" :key="$order['id']" />
        @endforeach
    </ol>
    <p class="grand">{{ $this->grandTotal }}</p>
    <div class="bumps">
        @foreach ($orders as $order)
            @foreach ($order['lines'] as $j => $line)
                <button type="button" class="bump" fusion:click="bump($order['id'], $j)">{{ $loop->parent->iteration }}.{{ $loop->iteration }}</button>
            @endforeach
            <button type="button" class="up" fusion:click="up($order['id'])">up {{ $order['id'] }}</button>
        @endforeach
    </div>
    <form class="draft" fusion:submit="save">
        <input class="customer" fusion:model="draft.customer">
        <span class="unsaved" fusion:dirty fusion:target="draft.customer">Unsaved name</span>
        <span class="any-change" fusion:dirty.class="changed" fusion:dirty.attr="data-changed">Changes</span>
        @error('draft.customer') <span class="error">{{ $message }}</span> @enderror
        @foreach ($draft['lines'] as $i => $line)
            <div class="draft-line">
                {{ $line['name'] }} × <input class="line-qty" type="number" fusion:model.number="draft.lines.{{ $i }}.qty">
                <fusion:qty-input fusion:model="draft.lines.{{ $i }}.qty" :key="'q'.$i" />
                <button type="button" class="drop" fusion:click="removeLine($i)">x</button>
            </div>
        @endforeach
        <button type="button" class="add-line" fusion:click="addLine">Add line</button>
        <button type="submit" class="save" fusion:loading.delay.100ms.class="busy" fusion:target="save">Save</button>
        <span class="saving" fusion:loading.delay.100ms fusion:target="save">Saving…</span>
        <span class="still-saving" fusion:loading.delay.2s fusion:target="save">Still saving…</span>
    </form>
    @teleport('dock')
        <div class="dock-summary">{{ $selected === null ? 'Nothing picked' : 'Picked #'.$selected }} · {{ count($log) }} actions</div>
    @endteleport
    <p class="log">{{ implode(', ', $log) }}</p>
</section>
