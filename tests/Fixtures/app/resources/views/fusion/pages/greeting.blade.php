<div class="greeting">
    <p class="hello">Hello {{ $this->shared('user.name', 'guest') }}</p>
    <p class="missing">{{ $this->shared('nothing.here', 'fallback') }}</p>
    <p class="shouted">{{ $shouted }}</p>
    <button class="shout" fusion:click="shout">Shout</button>
    <button class="rename" fusion:click="rename('Grace')">Rename</button>
    <a class="to-counter" href="/rendered/counter" fusion:navigate>Counter</a>
</div>
