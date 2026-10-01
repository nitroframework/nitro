<div class="feed">
    <ul class="items">
        @foreach ($items as $item)
            <li>{{ $item }}</li>
        @endforeach
    </ul>
    @if (count($items) < 20)
        <div class="more" fusion:visible.200px="loadMore"></div>
    @endif
    <p class="seen" fusion:visible.once="markSeen">{{ $seen }}</p>
    <p class="idle" fusion:visible="ping"></p>
    <button class="count-pings" fusion:click="countPings">{{ $pings }}</button>
</div>
