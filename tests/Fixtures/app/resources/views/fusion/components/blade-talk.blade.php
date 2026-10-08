<div class="talk">
    <button type="button" class="shout" fusion:click="shout">Shout</button>
    <button type="button" class="announce" fusion:click="announce">Announce</button>
    <button type="button" class="whisper" fusion:click="whisper">Whisper</button>
    <button type="button" class="view-dispatch" fusion:click="$dispatch('shouted', $engine.' view')">From the view</button>
    <span class="heard">{{ implode('; ', $heard) }}</span>
</div>
