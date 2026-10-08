<script>
    let { engine, heard, shout, announce, whisper, dispatch } = $props();
</script>

<div class="talk">
    <button type="button" class="shout" onclick={() => shout()}>Shout</button>
    <button type="button" class="announce" onclick={() => announce()}>Announce</button>
    <button type="button" class="whisper" onclick={() => whisper()}>Whisper</button>
    <button type="button" class="view-dispatch" onclick={() => dispatch('shouted', `${engine} view`)}>From the view</button>
    <span class="heard">{heard.join('; ')}</span>
</div>
