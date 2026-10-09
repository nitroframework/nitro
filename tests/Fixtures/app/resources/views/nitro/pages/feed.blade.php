<div class="feed">
    <ul class="items">
        @foreach ($items as $item)
            <li>{{ $item }}</li>
        @endforeach
    </ul>
    @if (count($items) < 20)
        <div class="more" nitro:visible.200px="loadMore"></div>
    @endif
    <p class="seen" nitro:visible.once="markSeen">{{ $seen }}</p>
    <p class="idle" nitro:visible="ping"></p>
    <button class="count-pings" nitro:click="countPings">{{ $pings }}</button>
</div>
