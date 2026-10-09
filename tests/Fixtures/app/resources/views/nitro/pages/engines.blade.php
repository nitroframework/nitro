<section class="engines">
    <div class="blade-side"><nitro:counter /></div>
    <div class="plain-side"><nitro:plain-counter :count="3" label="Server" /></div>
    <div class="vue-side"><nitro:vue-counter :count="1" /></div>
    <div class="vue-note-side"><nitro:vue-note /></div>
    <div class="react-side"><nitro:react-counter :count="1" /></div>
    <div class="react-note-side"><nitro:react-note /></div>
    <div class="solid-side"><nitro:solid-counter :count="1" /></div>
    <div class="solid-note-side"><nitro:solid-note /></div>
    <div class="colocated-vue"><nitro:colocated-tally /></div>
    <div class="colocated-react"><nitro:colocated-greeting /></div>
    <div class="colocated-svelte"><nitro:colocated-score /></div>
    <button type="button" class="more" nitro:click="$toggle('more')">More</button>
    @if ($more)
        <div class="plain-made"><nitro:plain-counter :count="10" label="Browser" /></div>
    @endif
</section>
