<div class="clock" fusion:init="start">
    <p class="label">{{ $this->label }}</p>
    <p class="again">{{ $this->label }}</p>
    <p class="synced">{{ $synced }}</p>
    <p class="shout">{{ $shout }}</p>
    @if ($ready)
        <em class="ready">ready</em>
    @endif
    @if ($polling)
        <span class="poller" fusion:poll.100ms="tick"></span>
        <span fusion:poll.250ms="sync"></span>
    @endif
    <button class="stop" fusion:click="$set('polling', false)">Stop</button>
    <button class="tick" fusion:click="tick">Tick</button>
    <button class="shout-it" fusion:click="shoutLabel">Shout</button>
    <button class="reset" fusion:click="$set('ticks', 0)" fusion:confirm="Reset the clock?">Reset</button>
    <button class="wipe" fusion:click="$set('ticks', 0)" fusion:confirm.prompt="Type WIPE to confirm|WIPE">Wipe</button>
</div>
