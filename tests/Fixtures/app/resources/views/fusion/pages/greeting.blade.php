<div class="greeting">
    <p class="hello">Hello {{ $this->shared('user.name', 'guest') }}</p>
    <p class="missing">{{ $this->shared('nothing.here', 'fallback') }}</p>
    <p class="shouted">{{ $shouted }}</p>
    <input class="draft" fusion:model="draft">
    <p class="draft-shown">{{ $draft }}</p>
    <button class="shout" fusion:click="shout">Shout</button>
    <button class="rename" fusion:click="rename('Grace')">Rename</button>
    <a class="to-counter" href="/rendered/counter" fusion:navigate>Counter</a>
    <a class="to-report" href="/report" fusion:navigate>Report</a>
    <a class="to-counter-kept" href="/rendered/counter" fusion:navigate.preserve-scroll>Counter, same scroll</a>
</div>
