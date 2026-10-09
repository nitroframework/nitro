<section class="lifecycle">
    <input type="text" class="title" nitro:model="title">
    <input type="text" class="email" nitro:model="form.email">
    <input type="text" class="first-name" nitro:model="first_name">
    <input type="text" class="code" nitro:model="code">
    <p class="checked">{{ $checked }}</p>
    <ol class="log">
        @foreach ($log as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ol>
    <p class="renders">{{ $renders }}</p>
    <button type="button" class="explode" nitro:click="explode">Explode</button>
    <p class="failure">{{ $failure }}</p>
    @if ($child)
        <nitro:lifecycle-child />
    @endif
    <button type="button" class="drop" nitro:click="$toggle('child')">Drop</button>
    <button type="button" class="count" nitro:click="countGone">Count</button>
    <p class="gone">{{ $gone }}</p>
</section>
