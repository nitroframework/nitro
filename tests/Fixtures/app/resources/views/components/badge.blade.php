@props(['tone' => 'gold'])
<span {{ $attributes->merge(['class' => 'badge badge-'.$tone]) }}>{{ $slot->isEmpty() ? 'Empty' : '' }}{{ $slot }}</span>
