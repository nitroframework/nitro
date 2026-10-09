<div class="talk">
    <button type="button" class="shout" nitro:click="shout">Shout</button>
    <button type="button" class="announce" nitro:click="announce">Announce</button>
    <button type="button" class="whisper" nitro:click="whisper">Whisper</button>
    <button type="button" class="view-dispatch" nitro:click="$dispatch('shouted', $engine.' view')">From the view</button>
    <span class="heard">{{ implode('; ', $heard) }}</span>
</div>
