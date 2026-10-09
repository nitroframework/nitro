<div class="greeting">
    <p class="hello">Hello {{ $this->shared('user.name', 'guest') }}</p>
    <p class="missing">{{ $this->shared('nothing.here', 'fallback') }}</p>
    <p class="shouted">{{ $shouted }}</p>
    <input class="draft" nitro:model="draft">
    <p class="draft-shown">{{ $draft }}</p>
    <button class="shout" nitro:click="shout">Shout</button>
    <button class="rename" nitro:click="rename('Grace')">Rename</button>
    <p class="countries">{{ count($this->shared('countries', [])) }} countries</p>
    <p class="toast">{{ $this->flashed('toast', 'no toast') }}</p>
    <button class="celebrate" nitro:click="celebrate">Celebrate</button>
    <button class="celebrate-elsewhere" nitro:click="celebrateElsewhere">Celebrate elsewhere</button>
    <a class="to-counter" href="/counter" nitro:navigate>Counter</a>
    <a class="to-report" href="/report" nitro:navigate>Report</a>
    <a class="to-inbox" href="/inbox" nitro:navigate>Inbox</a>
    <button class="go" nitro:click="$visit('/counter')">Go</button>
    <a class="to-counter-kept" href="/counter" nitro:navigate.preserve-scroll>Counter, same scroll</a>
</div>
