<section class="gallery">
    <input type="file" class="photo" nitro:model="photo">
    <input type="file" class="photos" nitro:model="photos" multiple>
    <span class="uploading" nitro:loading nitro:target="photo">Uploading…</span>
    @error('photo') <p class="error">{{ $message }}</p> @enderror
    @if ($photo)
        <figure class="preview">
            @if (str_starts_with($photo->getMimeType(), 'image/'))
                <img src="{{ $photo->temporaryUrl() }}" alt="{{ $photo->getClientOriginalName() }}">
            @endif
            <figcaption>{{ $photo->getClientOriginalName() }} ({{ $photo->getClientOriginalExtension() }}, {{ $photo->getMimeType() }}, {{ $photo->getSize() }} bytes)</figcaption>
        </figure>
    @endif
    <ul class="photos">
        @foreach ($photos as $upload)
            <li>{{ $upload->getClientOriginalName() }}</li>
        @endforeach
    </ul>
    <p class="count">{{ count($photos) }} photos</p>
    <button class="save" nitro:click="save">Save</button>
    @if ($stored)
        <p class="stored">{{ $stored['photo'] }}</p>
    @endif
</section>
