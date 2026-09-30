<!DOCTYPE html>
<html>
<head>
    <title>{{ $title ?? 'Fusion' }}</title>
</head>
<body>
    <nav>
        <a href="/counter" fusion:navigate fusion:current="active">Counter</a>
        <a href="/board" fusion:navigate fusion:current="active" class="board-link">Board</a>
        <a href="/counter" fusion:navigate.keypress class="press-link">Counter (on press)</a>
    </nav>
    <main>{{ $slot }}</main>
    <div id="toasts">@portal('toasts')</div>
    <aside id="dock">@portal('dock')</aside>
    @fusionScripts
</body>
</html>
