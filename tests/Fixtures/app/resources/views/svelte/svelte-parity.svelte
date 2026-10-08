<script>
    /** title and form are $bindable: bind:value writes them back, form deep inside. */
    let { count, doubled, title = $bindable(), form = $bindable(), said, photo, photos, increment, addTag, save, upload, uploads, link, unsaved, errors } = $props();
</script>

<div class="parity">
    <span class="count">{count}</span> <span class="doubled">{doubled}</span>
    <button type="button" class="inc" onclick={() => increment(3)}>+3</button>
    <input class="title" bind:value={title}>
    <input class="email" bind:value={form.email}>
    <span class="email-shown">{form.email}</span>
    <span class="tags">{form.tags.join(',')}</span>
    <button type="button" class="tag" onclick={() => addTag('b')}>Tag</button>
    <button type="button" class="save" disabled={save.processing} onclick={save}>Save</button>
    {#if save.processing}<span class="busy">Saving</span>{/if}
    {#if save.slow}<span class="slow">Still saving</span>{/if}
    {#if unsaved.length}<span class="unsaved">Unsaved changes</span>{/if}
    <span class="error">{errors.title?.[0]}</span>
    <span class="said">{said}</span>
    <input type="file" class="photo" onchange={(e) => upload('photo', e.currentTarget.files)}>
    <input type="file" class="photos" multiple onchange={(e) => upload('photos', e.currentTarget.files)}>
    <span class="photo-name">{photo?.getClientOriginalName()}</span>
    <span class="photos-count">{photos.length}</span>
    <a class="away" {...link('/counter', 'prefetch')}>Away</a>
    <span class="photo-error">{errors.photo?.[0]}</span>
    <span class="photo-progress">{uploads.photo?.progress}</span>
</div>
