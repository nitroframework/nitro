<div class="clock" nitro:init="start">
    <p class="label">{{ $this->label }}</p>
    <p class="again">{{ $this->label }}</p>
    <p class="synced">{{ $synced }}</p>
    <p class="shout">{{ $shout }}</p>
    @if ($ready)
        <em class="ready">ready</em>
    @endif
    @if ($polling)
        <span class="poller" nitro:poll.100ms="tick"></span>
        <span nitro:poll.250ms="sync"></span>
    @endif
    <button class="stop" nitro:click="$set('polling', false)">Stop</button>
    <button class="tick" nitro:click="tick">Tick</button>
    <button class="shout-it" nitro:click="shoutLabel">Shout</button>
    <button class="reset" nitro:click="$set('ticks', 0)" nitro:confirm="Reset the clock?">Reset</button>
    <button class="wipe" nitro:click="$set('ticks', 0)" nitro:confirm.prompt="Type WIPE to confirm|WIPE">Wipe</button>
</div>
