{{-- @props([$broken]) and {{ $slot }} or $slot, $attributes --}}
<div>
    @verbatim<code>{{ $slot }} {{ $attributes }}</code>@endverbatim
    <p>{{ $slot }}</p>
</div>
