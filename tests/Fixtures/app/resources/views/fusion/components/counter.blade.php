<div class="counter">
    <button fusion:click="decrement">-</button>
    <span class="count">{{ $count }}</span>
    <button fusion:click="increment">+</button>
    <p>Doubled: {{ $this->doubled() }}</p>
    <p class="raw">Raw: {!! $count > 0 ? '<b>'.$count.'</b>' : '<i>none</i>' !!} and <span class="tail">tail</span></p>
    @if ($count > 1000)
        <em class="huge">Huge</em>
    @endif
    @if ($count > 5)
        <strong>High!</strong>
        <input type="text" class="why" autofocus>
    @elseif ($count < 0)
        <em>Negative</em>
    @else
        <small>Normal</small>
    @endif
    <ul>
    @foreach ($history as $i => $value)
        <li @class(['even' => $loop->even, 'last' => $loop->last])>{{ $loop->iteration }}/{{ $loop->count }}: {{ $value }}</li>
    @endforeach
    </ul>
    <input type="number" fusion:model.number="step">
    <p class="entries">
    @foreach ($history as $i => $value)
        <input class="entry" type="number" fusion:model.number="history.{{ $i }}">
    @endforeach
    </p>
    <button fusion:click="save" fusion:loading.attr="disabled" fusion:target="save">Save</button>
    @for ($n = 1; $n <= 3; $n++)
        <button class="set" fusion:click="$set('count', $n * 10)">{{ $n }}</button>
    @endfor
</div>
