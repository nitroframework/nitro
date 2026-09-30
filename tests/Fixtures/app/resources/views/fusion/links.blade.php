<nav class="links">
    <a class="named" href="{{ route('students.show', ['student' => $studentId, 'tab' => 'notes']) }}">{{ __('Open :name', ['name' => $name]) }}</a>
    <a class="positional" href="{{ route('students.show', [$studentId]) }}">positional</a>
    <a class="scalar" href="{{ route('students.show', $studentId) }}">scalar</a>
    @if ($student !== null)
        <a class="model" href="{{ route('students.show', ['student' => $student]) }}">model</a>
    @endif
    <a class="query" href="{{ route('counter', ['q' => 'a b&c', 'tags' => ['x', 'y'], 'on' => true]) }}">query</a>
    <a class="relative" href="{{ route('counter', ['page' => 2], false) }}">relative</a>
    <a class="optional" href="{{ route('archive') }}">optional</a>
    <a class="optional-given" href="{{ route('archive', ['year' => 2026]) }}">optional given</a>
    <a class="bound" href="{{ route('posts.show', ['post' => $slug]) }}">bound</a>
    <a class="next" href="{{ $this->nextStudent() }}">next</a>
    <a class="url" href="{{ url('help/topics', ['a b', 'c/d']) }}">url</a>
    <a class="url-query" href="{{ url('search?q=1') }}">url query</a>
    <a class="absolute" href="{{ url('https://example.com/x') }}">absolute</a>
    <img src="{{ asset('img/logo.png') }}" alt="{{ config('app.name') }}">
    <span class="welcome">{{ __('messages.welcome', ['name' => $name]) }}</span>
    <span class="missing">{{ __('Nothing to translate') }}</span>
    <span class="plain">{{ trans('Open :name') }}</span>
    <span class="str">{{ $this->handle() }} {{ Str::limit(Str::title($name), 8) }} {{ \Illuminate\Support\Str::padLeft($studentId, 4, "0") }} {{ Str::mask("secret@example.com", "*", 2, 6) }}</span>
    <span class="preg">{{ $this->initials() }} {{ preg_replace('/\s+/', '-', $name) }} {{ implode('|', preg_split('/[\s,]+/', "a, b  c")) }}</span>
    <span class="words" title="{{ strtr($slug, "/?", "-_") }}">{{ str_word_count($name) }} {{ ucwords(strrev($slug)) }} {{ levenshtein($name, "nina") }}</span>
    <button fusion:click="rename">Rename</button>
</nav>
