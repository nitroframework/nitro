<!DOCTYPE html>
<html>
<head>
    <title>{{ $title ?? 'Tools' }}</title>
    {{-- The libraries this layout's pages use, and their bindings: a visit into it loads them after Nitro started. --}}
    @foreach (['jquery', 'select2', 'flatpickr', 'tom-select', 'choices', 'trix', 'quill', 'sortable', 'nouislider', 'imask', 'libraries'] as $library)
        <script src="/_test/lib/{{ $library }}.js"></script>
    @endforeach
</head>
<body>
    {{ $slot }}
    @nitroScripts
</body>
</html>
