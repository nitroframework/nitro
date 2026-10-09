<article class="card">
    <header>
        <h3>{{ $title }}</h3>
        <span class="heard">{{ $heard }}</span>
        <button type="button" class="toggle" nitro:click="$toggle('open')">{{ $open ? 'Hide' : 'Show' }}</button>
    </header>
    @if ($open)
        <div class="body">{{ $slot }}</div>
    @endif
    @if ($slot->isEmpty())
        <p class="blank">Nothing here yet.</p>
    @endif
    @if ($slots->has('footer'))
        <footer>{{ $slots['footer'] }}</footer>
    @endif
</article>
