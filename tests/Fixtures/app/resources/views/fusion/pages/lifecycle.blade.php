<section class="lifecycle">
    <input type="text" class="title" fusion:model="title">
    <input type="text" class="email" fusion:model="form.email">
    <input type="text" class="first-name" fusion:model="first_name">
    <input type="text" class="code" fusion:model="code">
    <p class="checked">{{ $checked }}</p>
    <ol class="log">
        @foreach ($log as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ol>
    <p class="renders">{{ $renders }}</p>
    <button type="button" class="explode" fusion:click="explode">Explode</button>
    <p class="failure">{{ $failure }}</p>
    @if ($child)
        <fusion:lifecycle-child />
    @endif
    <button type="button" class="drop" fusion:click="$toggle('child')">Drop</button>
    <button type="button" class="count" fusion:click="countGone">Count</button>
    <p class="gone">{{ $gone }}</p>
</section>
