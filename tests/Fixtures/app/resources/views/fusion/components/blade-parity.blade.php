<div class="parity">
    <span class="count">{{ $count }}</span> <span class="doubled">{{ $this->doubled }}</span>
    <button type="button" class="inc" fusion:click="increment(3)">+3</button>
    <input class="title" fusion:model="title">
    <input class="email" fusion:model="form.email">
    <span class="email-shown">{{ $form['email'] }}</span>
    <span class="tags">{{ implode(',', $form['tags']) }}</span>
    <button type="button" class="tag" fusion:click="addTag('b')">Tag</button>
    <button type="button" class="save" fusion:click="save" fusion:loading.attr="disabled" fusion:target="save">Save</button>
    <span class="busy" fusion:loading fusion:target="save">Saving</span>
    <span class="slow" fusion:loading.delay fusion:target="save">Still saving</span>
    <span class="unsaved" fusion:dirty>Unsaved changes</span>
    <span class="error">@error('title'){{ $message }}@enderror</span>
    <span class="said">{{ $said }}</span>
    <input type="file" class="photo" fusion:model="photo">
    <input type="file" class="photos" multiple fusion:model="photos">
    <span class="photo-name">{{ $photo?->getClientOriginalName() }}</span>
    <span class="photos-count">{{ count($photos) }}</span>
    <a class="away" href="/counter" fusion:navigate.prefetch>Away</a>
    <span class="photo-error">@error('photo'){{ $message }}@enderror</span>
</div>
