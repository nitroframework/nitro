@props(['name' => null])
<label data-for="{{ $name ?? $attributes->get('nitro:model') }}" data-lazy="{{ $attributes->get('nitro:lazy', 'no') }}">
    <input {{ $attributes }} />
</label>
