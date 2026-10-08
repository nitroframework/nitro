@props(['variant' => 'primary'])
<button type="button" {{ $attributes->merge(['class' => 'btn btn-'.$variant]) }}>{{ $slot }}</button>
