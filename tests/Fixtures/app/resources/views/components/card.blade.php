@props(['title', 'shadow' => false])
<div {{ $attributes->class(['card', 'shadow' => $shadow]) }}>
    <h3>{{ $title }}</h3>
    <div class="body">{{ $slot }}</div>
    @isset($footer)
        <footer>{{ $footer }}</footer>
    @endisset
    @if ($subtitle ?? false)
        <p>{{ $subtitle }}</p>
    @endif
</div>
