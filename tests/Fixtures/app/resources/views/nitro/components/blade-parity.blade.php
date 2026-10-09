<div class="parity">
    <span class="count">{{ $count }}</span> <span class="doubled">{{ $this->doubled }}</span>
    <button type="button" class="inc" nitro:click="increment(3)">+3</button>
    <input class="title" nitro:model="title">
    <input class="email" nitro:model="form.email">
    <span class="email-shown">{{ $form['email'] }}</span>
    <span class="tags">{{ implode(',', $form['tags']) }}</span>
    <button type="button" class="tag" nitro:click="addTag('b')">Tag</button>
    <button type="button" class="save" nitro:click="save" nitro:loading.attr="disabled" nitro:target="save">Save</button>
    <span class="busy" nitro:loading nitro:target="save">Saving</span>
    <span class="slow" nitro:loading.delay nitro:target="save">Still saving</span>
    <span class="unsaved" nitro:dirty>Unsaved changes</span>
    <span class="error">@error('title'){{ $message }}@enderror</span>
    <span class="said">{{ $said }}</span>
    <input type="file" class="photo" nitro:model="photo">
    <input type="file" class="photos" multiple nitro:model="photos">
    <span class="photo-name">{{ $photo?->getClientOriginalName() }}</span>
    <span class="photos-count">{{ count($photos) }}</span>
    <a class="away" href="/counter" nitro:navigate.prefetch>Away</a>
    <span class="photo-error">@error('photo'){{ $message }}@enderror</span>
</div>
