@use('Nitro\Tests\Fixtures\Browser\OrderStatus')
<section class="stress">
    @include('partials.heading', ['title' => 'Orders ('.count($orders).')', 'level' => 2])
    <input class="filter" nitro:model.live="filter">
    <select class="sort" nitro:model="sort">
        <option value="id">Id</option>
        <option value="customer">Customer</option>
        <option value="total">Total</option>
    </select>
    <button type="button" class="advance" nitro:click="advance">Focus: {{ $focus->label() }}</button>
    <ol class="orders">
        @foreach ($this->visible as $order)
            <nitro:stress-row :order="$order" :selected="$selected === $order['id']" :focus="$focus->value" :key="$order['id']" />
        @endforeach
    </ol>
    <p class="grand">{{ $this->grandTotal }}</p>
    <div class="bumps">
        @foreach ($orders as $order)
            @foreach ($order['lines'] as $j => $line)
                <button type="button" class="bump" nitro:click="bump($order['id'], $j)">{{ $loop->parent->iteration }}.{{ $loop->iteration }}</button>
            @endforeach
            <button type="button" class="up" nitro:click="up($order['id'])">up {{ $order['id'] }}</button>
        @endforeach
    </div>
    <form class="draft" nitro:submit="save">
        <input class="customer" nitro:model="draft.customer">
        <span class="unsaved" nitro:dirty nitro:target="draft.customer">Unsaved name</span>
        <span class="any-change" nitro:dirty.class="changed" nitro:dirty.attr="data-changed">Changes</span>
        @error('draft.customer') <span class="error">{{ $message }}</span> @enderror
        @foreach ($draft['lines'] as $i => $line)
            <div class="draft-line">
                {{ $line['name'] }} × <input class="line-qty" type="number" nitro:model.number="draft.lines.{{ $i }}.qty">
                <nitro:qty-input nitro:model="draft.lines.{{ $i }}.qty" :key="'q'.$i" />
                <button type="button" class="drop" nitro:click="removeLine($i)">x</button>
            </div>
        @endforeach
        <button type="button" class="add-line" nitro:click="addLine">Add line</button>
        <button type="submit" class="save" nitro:loading.delay.100ms.class="busy" nitro:target="save">Save</button>
        <span class="saving" nitro:loading.delay.100ms nitro:target="save">Saving…</span>
        <span class="still-saving" nitro:loading.delay.2s nitro:target="save">Still saving…</span>
    </form>
    @teleport('dock')
        <div class="dock-summary">{{ $selected === null ? 'Nothing picked' : 'Picked #'.$selected }} · {{ count($log) }} actions</div>
    @endteleport
    <p class="log">{{ implode(', ', $log) }}</p>
</section>
