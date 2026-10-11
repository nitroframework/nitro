<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @nitroHead
    {{-- The views first: they register with Nitro before its components start. --}}
    @vite('resources/js/app.js')
</head>
<body>
    <aside id="dock">@portal('dock')</aside>

    {{ $slot }}

    @nitroScripts
</body>
</html>
