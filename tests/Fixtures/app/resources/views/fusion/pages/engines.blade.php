<section class="engines">
    <div class="blade-side"><fusion:counter /></div>
    <div class="plain-side"><fusion:plain-counter :count="3" label="Server" /></div>
    <div class="vue-side"><fusion:vue-counter :count="1" /></div>
    <div class="vue-note-side"><fusion:vue-note /></div>
    <div class="react-side"><fusion:react-counter :count="1" /></div>
    <div class="react-note-side"><fusion:react-note /></div>
    <div class="solid-side"><fusion:solid-counter :count="1" /></div>
    <div class="solid-note-side"><fusion:solid-note /></div>
    <div class="colocated-vue"><fusion:colocated-tally /></div>
    <div class="colocated-react"><fusion:colocated-greeting /></div>
    <div class="colocated-svelte"><fusion:colocated-score /></div>
    <button type="button" class="more" fusion:click="$toggle('more')">More</button>
    @if ($more)
        <div class="plain-made"><fusion:plain-counter :count="10" label="Browser" /></div>
    @endif
</section>
